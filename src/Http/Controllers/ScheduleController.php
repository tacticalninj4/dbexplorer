<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Closure;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Models\SavedQuery;
use TacticalNinj4\DbExplorer\Models\Schedule;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;
use TacticalNinj4\DbExplorer\Sql\Classifier;
use Throwable;

/**
 * The schedules API (PRD §7.9), admins only: list, create, re-sync, pause
 * or resume, and delete schedules for saved queries on the label.
 *
 * Creating or re-syncing a schedule is its approval (docs/security.md §6):
 * the saved query's SQL and parameters are copied into the schedule, and
 * the schedule runs that snapshot as the approving admin (re-checked at
 * every run) — later edits to the saved query change nothing until an
 * admin re-syncs. It runs in a read-only transaction unless the approval
 * set `allow_writes`; DDL also needs `allow_ddl`. Allowing either needs a
 * recent password confirmation (S5). Saved queries with required
 * parameters cannot be scheduled — there is no one to answer a prompt at
 * 3 a.m.
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
            'allow_writes' => ['sometimes', 'boolean'],
            'allow_ddl' => ['sometimes', 'boolean'],
            'notify' => ['sometimes', 'array'],
            'notify.on' => ['sometimes', 'array'],
            'notify.on.*' => ['in:success,failure,threshold'],
            'notify.threshold_rows' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'notify.mail' => ['sometimes', 'array', 'max:16'],
            'notify.mail.*' => ['email'],
        ]);

        $saved = $this->usableSavedQuery($request, (int) $input['saved_query_id']);
        [$allowWrites, $allowDdl] = $this->approval($request, $saved, $input);

        $schedule = (new Schedule([
            'user_id' => $this->userId($request),
            'cron' => (string) $input['cron'],
            'timezone' => $input['timezone'] ?? null,
            'enabled' => (bool) ($input['enabled'] ?? true),
            'notify' => $this->notify($input),
        ]))->approve($saved, $this->userId($request), $allowWrites, $allowDdl);

        $schedule->save();
        $this->auditApproval($schedule, 'schedule.created');

        return new JsonResponse(['data' => $this->toApi($schedule->refresh(), $this->userId($request))], 201);
    }

    /**
     * Copy the saved query's current SQL and parameters into the schedule
     * again, approved by the current admin with the write and DDL
     * permissions given now (both off unless sent). Audited.
     *
     * @throws ValidationException
     */
    public function resync(Request $request, string $label, string $schedule): JsonResponse
    {
        $this->authorize($request);

        $model = $this->find($request, $schedule);

        $input = $request->validate([
            'allow_writes' => ['sometimes', 'boolean'],
            'allow_ddl' => ['sometimes', 'boolean'],
        ]);

        $saved = $this->usableSavedQuery($request, $model->saved_query_id);
        [$allowWrites, $allowDdl] = $this->approval($request, $saved, $input);

        $model->approve($saved, $this->userId($request), $allowWrites, $allowDdl)->save();
        $this->auditApproval($model, 'schedule.resynced');

        return new JsonResponse(['data' => $this->toApi($model->refresh(), $this->userId($request))]);
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

        if (($changes['enabled'] ?? false) === true && ! $model->isApproved()) {
            throw ValidationException::withMessages(['enabled' => ['This schedule has no approved SQL. Re-sync it from its saved query first.']]);
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

    /**
     * The saved query the admin may schedule: their own or a shared one on
     * the label. Anything else is a 404.
     */
    private function usableSavedQuery(Request $request, int $id): SavedQuery
    {
        $saved = SavedQuery::query()
            ->usableBy($this->userId($request), (string) $request->attributes->get(ResolveRole::LABEL))
            ->whereKey($id)
            ->first();

        abort_unless($saved instanceof SavedQuery, 404);

        return $saved;
    }

    /**
     * The write and DDL permissions an approval grants, checked against the
     * SQL's classification the way the query API checks it: anything but a
     * read needs `allow_writes`, DDL needs `allow_ddl`, and granting either
     * needs a recent password confirmation (423, S5). The classifier is UX
     * (S11): the read-only transaction and the grants are the boundary.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: bool, 1: bool}
     *
     * @throws ValidationException
     */
    private function approval(Request $request, SavedQuery $saved, array $input): array
    {
        foreach ((array) $saved->params as $param) {
            if (is_array($param) && ($param['required'] ?? true)) {
                throw ValidationException::withMessages(['saved_query_id' => ["{$saved->name} needs a value for :{$param['name']} when it runs, so it cannot be scheduled."]]);
            }
        }

        $allowDdl = (bool) ($input['allow_ddl'] ?? false);
        $allowWrites = $allowDdl || (bool) ($input['allow_writes'] ?? false);
        $classification = Classifier::classify($saved->sql);

        if (Classifier::isDdl($saved->sql) && ! $allowDdl) {
            throw ValidationException::withMessages(['allow_ddl' => ["{$saved->name} looks like DDL. Approve it with allow_ddl to schedule it."]]);
        }

        if (in_array($classification, [Classifier::DML, Classifier::DESTRUCTIVE, Classifier::DDL], true) && ! $allowWrites) {
            throw ValidationException::withMessages(['allow_writes' => ["{$saved->name} looks like {$classification} SQL. Approve it with allow_writes to schedule it; otherwise it runs read-only."]]);
        }

        if ($allowWrites) {
            app(PasswordConfirmation::class)->ensureConfirmed($request);
        }

        return [$allowWrites, $allowDdl];
    }

    private function auditApproval(Schedule $schedule, string $action): void
    {
        DbExplorer::audit([
            'action' => $action,
            'sql' => $schedule->sql,
            'classification' => Classifier::classify((string) $schedule->sql),
            'outcome' => 'success',
            'meta' => [
                'schedule_id' => $schedule->id,
                'saved_query_id' => $schedule->saved_query_id,
                'sql_hash' => $schedule->sql_hash,
                'allow_writes' => $schedule->allow_writes,
                'allow_ddl' => $schedule->allow_ddl,
            ],
        ]);
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
            'sql' => $schedule->sql,
            'approved' => $schedule->isApproved(),
            'approved_by' => $schedule->approved_by,
            'approved_at' => $schedule->approved_at?->toIso8601String(),
            'allow_writes' => $schedule->allow_writes,
            'allow_ddl' => $schedule->allow_ddl,
            // false once the saved query's SQL differs from the approved snapshot
            'in_sync' => $schedule->savedQuery !== null && $schedule->sql_hash === hash('sha256', $schedule->savedQuery->sql),
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
