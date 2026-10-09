<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Closure;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Models\SavedQuery;
use TacticalNinj4\DbExplorer\Models\Schedule;
use TacticalNinj4\DbExplorer\Role;
use Throwable;

/**
 * The schedules API (PRD §7.9), admins only: list, create, pause or resume,
 * and delete schedules for saved queries on the label. A schedule runs its
 * query as its creator (an admin, re-checked at every run), so saved
 * queries with required parameters cannot be scheduled — there is no one
 * to answer a prompt at 3 a.m.
 */
class ScheduleController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize($request);

        $label = (string) $request->attributes->get(ResolveRole::LABEL);
        $userId = $this->userId($request);

        $schedules = Schedule::query()
            ->whereHas('savedQuery', fn (Builder $query) => $query->where('label', $label))
            ->with('savedQuery')
            ->orderBy('id')
            ->get();

        return new JsonResponse(['data' => $schedules->map(fn (Schedule $schedule): array => $this->toApi($schedule, $userId))->all()]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize($request);

        $input = $request->validate([
            'saved_query_id' => ['required', 'integer'],
            'cron' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    new CronExpression((string) $value);
                } catch (Throwable) {
                    $fail('The cron expression is not valid.');
                }
            }],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! in_array($value, timezone_identifiers_list(), true)) {
                    $fail('The timezone is not valid.');
                }
            }],
            'enabled' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'array'],
            'notify.on' => ['sometimes', 'array'],
            'notify.on.*' => ['in:success,failure,threshold'],
            'notify.threshold_rows' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'notify.mail' => ['sometimes', 'array', 'max:16'],
            'notify.mail.*' => ['email'],
        ]);

        $saved = SavedQuery::query()
            ->usableBy($this->userId($request), (string) $request->attributes->get(ResolveRole::LABEL))
            ->whereKey((int) $input['saved_query_id'])
            ->first();

        abort_unless($saved instanceof SavedQuery, 404);

        foreach ((array) $saved->params as $param) {
            if (is_array($param) && ($param['required'] ?? true)) {
                throw ValidationException::withMessages(['saved_query_id' => ["{$saved->name} needs a value for :{$param['name']} when it runs, so it cannot be scheduled."]]);
            }
        }

        $schedule = Schedule::query()->create([
            'saved_query_id' => $saved->id,
            'user_id' => $this->userId($request),
            'cron' => (string) $input['cron'],
            'timezone' => $input['timezone'] ?? null,
            'enabled' => (bool) ($input['enabled'] ?? true),
            'notify' => $this->notify($input),
        ]);

        return new JsonResponse(['data' => $this->toApi($schedule->refresh(), $this->userId($request))], 201);
    }

    public function update(Request $request, string $label, string $schedule): JsonResponse
    {
        $this->authorize($request);

        $model = $this->find($request, $schedule);

        $input = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'cron' => ['sometimes', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    new CronExpression((string) $value);
                } catch (Throwable) {
                    $fail('The cron expression is not valid.');
                }
            }],
            'notify' => ['sometimes', 'array'],
            'notify.on' => ['sometimes', 'array'],
            'notify.on.*' => ['in:success,failure,threshold'],
            'notify.threshold_rows' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'notify.mail' => ['sometimes', 'array', 'max:16'],
            'notify.mail.*' => ['email'],
        ]);

        $changes = array_filter([
            'enabled' => $input['enabled'] ?? null,
            'cron' => $input['cron'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        if (array_key_exists('notify', $input)) {
            $changes['notify'] = $this->notify($input);
        }

        $model->update($changes);

        return new JsonResponse(['data' => $this->toApi($model->refresh(), $this->userId($request))]);
    }

    public function destroy(Request $request, string $label, string $schedule): JsonResponse
    {
        $this->authorize($request);

        $this->find($request, $schedule)->delete();

        return new JsonResponse([], 204);
    }

    private function authorize(Request $request): void
    {
        abort_unless((string) $request->attributes->get(ResolveRole::ROLE) === Role::ADMIN, 403, 'Only admins can schedule queries.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function notify(array $input): array
    {
        $notify = $input['notify'] ?? [];

        return array_filter([
            'on' => array_values((array) ($notify['on'] ?? [])),
            'threshold_rows' => $notify['threshold_rows'] ?? null,
            'mail' => array_values((array) ($notify['mail'] ?? [])),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function toApi(Schedule $schedule, string $userId): array
    {
        return [
            'id' => $schedule->id,
            'saved_query_id' => $schedule->saved_query_id,
            'name' => $schedule->savedQuery?->name,
            'owner' => $schedule->user_id === $userId,
            'cron' => $schedule->cron,
            'timezone' => $schedule->timezone,
            'enabled' => $schedule->enabled,
            'notify' => $schedule->notifySettings(),
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'last_run_id' => $schedule->last_run_id,
            'created_at' => $schedule->created_at->toIso8601String(),
        ];
    }

    private function userId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : '';
    }

    private function find(Request $request, string $id): Schedule
    {
        abort_unless(ctype_digit($id), 404);

        $schedule = Schedule::query()
            ->whereHas('savedQuery', fn (Builder $query) => $query->where('label', (string) $request->attributes->get(ResolveRole::LABEL)))
            ->with('savedQuery')
            ->whereKey((int) $id)
            ->first();

        abort_unless($schedule instanceof Schedule, 404);

        return $schedule;
    }
}
