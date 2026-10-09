<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Jobs\RunQuery;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\SavedQuery;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;
use TacticalNinj4\DbExplorer\Sql\Classifier;
use TacticalNinj4\DbExplorer\Sql\NamedParameters;
use Throwable;

/**
 * The saved queries API (PRD §7.8): named SQL with typed `:parameters`,
 * private or shared. Sharing shows a query to everyone who can use the
 * label; running it still needs the role its classification requires —
 * the same gate, confirmation ladder and database grants as the SQL editor.
 */
class SavedQueryController
{
    /**
     * The user's own saved queries on the label, and the shared ones.
     */
    public function index(Request $request): JsonResponse
    {
        $id = $request->user()?->getAuthIdentifier();

        abort_unless(is_scalar($id), 403);

        $queries = SavedQuery::query()->usableBy((string) $id, (string) $request->attributes->get(ResolveRole::LABEL))->get();

        return new JsonResponse(['data' => $queries->map(fn (SavedQuery $query): array => $this->toApi($query, (string) $id))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $query = SavedQuery::query()->create($this->validated($request) + [
            'user_id' => (string) $request->user()?->getAuthIdentifier(),
            'label' => (string) $request->attributes->get(ResolveRole::LABEL),
        ]);

        return new JsonResponse(['data' => $this->toApi($query, (string) $request->user()?->getAuthIdentifier())], 201);
    }

    public function update(Request $request, string $label, string $query): JsonResponse
    {
        $model = $this->findOwned($request, $query);
        $model->update($this->validated($request));

        return new JsonResponse(['data' => $this->toApi($model->refresh(), (string) $request->user()?->getAuthIdentifier())]);
    }

    public function destroy(Request $request, string $label, string $query): JsonResponse
    {
        $this->findOwned($request, $query)->delete();

        return new JsonResponse([], 204);
    }

    /**
     * Run a saved query now, or queue it in the background. The typed
     * parameter values are bound as `?` by the lexer-aware rewriter, so a
     * `':name'` inside a string literal is never one. The classification
     * gate mirrors the SQL editor's: DDL is admin-only (403), anything but
     * a read asks for `confirm` (409), destructive also for a recent
     * password confirmation (423, S5) — and the database grants are the
     * boundary, as ever. Only reads can go to the background (202).
     *
     * @throws ValidationException
     */
    public function run(Request $request, QueryRunner $runner, PasswordConfirmation $confirmation, string $label, string $query): JsonResponse
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless(is_scalar($id), 403);

        $model = $this->findUsable($request, $query);
        $role = (string) $request->attributes->get(ResolveRole::ROLE);

        $input = $request->validate([
            'values' => ['sometimes', 'array'],
            'background' => ['sometimes', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        try {
            [$sql, $bindings] = NamedParameters::bind((string) $model->sql, (array) $model->params, (array) ($input['values'] ?? []));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['values' => [$exception->getMessage()]]);
        }

        $classification = Classifier::classify($sql);

        if ((bool) ($input['background'] ?? false)) {
            if ($classification !== Classifier::READ) {
                throw ValidationException::withMessages(['background' => ['Only SELECT-style queries can run in the background.']]);
            }

            $run = Run::queueFor($request, RunQuery::TYPE, ['sql' => $sql, 'bindings' => $bindings, 'saved_query_id' => $model->id]);
            RunQuery::dispatch($run->id);

            return new JsonResponse(['data' => $run->toApi()], 202);
        }

        abort_if($role !== Role::ADMIN && Classifier::isDdl($sql), 403, 'Only admins can run DDL (CREATE, ALTER, DROP, TRUNCATE, GRANT, ...).');

        if ($classification !== Classifier::READ && ! (bool) ($input['confirm'] ?? false)) {
            return new JsonResponse(['error' => [
                'message' => "This saved query looks like {$classification} SQL. Confirm to run it.",
                'code' => 'confirmation_required',
                'classification' => $classification,
            ]], 409);
        }

        if ($classification === Classifier::DESTRUCTIVE) {
            $confirmation->ensureConfirmed($request);
        }

        $result = $runner->run((string) $request->attributes->get(ResolveRole::CONNECTION), $sql, $bindings, $role);

        return new JsonResponse(['data' => [
            'columns' => $result->columns,
            'rows' => $result->rows,
            'truncated' => $result->truncated,
            'row_count' => $result->rowCount,
            'affected_rows' => $result->affectedRows,
            'duration_ms' => $result->durationMs,
            'classification' => $result->classification,
            'notices' => $result->notices,
            'notices_supported' => $result->noticesSupported,
        ]]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(Request $request): array
    {
        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'sql' => ['sometimes', 'required', 'string', 'max:'.(int) config('dbexplorer.limits.sql_length', 100000)],
            'params' => ['sometimes', 'nullable', 'array', 'max:20'],
            'params.*.name' => ['required_with:params', 'string', 'max:63'],
            'params.*.type' => ['sometimes', 'in:string,int,date,enum'],
            'params.*.options' => ['sometimes', 'array', 'max:32'],
            'params.*.options.*' => ['string', 'max:191'],
            'params.*.required' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'in:'.implode(',', SavedQuery::VISIBILITIES)],
            'tags' => ['sometimes', 'nullable', 'array', 'max:16'],
            'tags.*' => ['string', 'max:63'],
        ]);

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function toApi(SavedQuery $query, string $userId): array
    {
        return [
            'id' => $query->id,
            'name' => $query->name,
            'description' => $query->description,
            'sql' => $query->sql,
            'params' => $query->params ?? [],
            'visibility' => $query->visibility,
            'tags' => $query->tags ?? [],
            'owned' => $query->isOwnedBy($userId),
            'created_at' => $query->created_at->toIso8601String(),
            'updated_at' => $query->updated_at->toIso8601String(),
        ];
    }

    private function userId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * A saved query the user can run on the label: their own, or shared.
     * Anything else is a 404, so ids can't be probed.
     */
    private function findUsable(Request $request, string $id): SavedQuery
    {
        abort_unless(ctype_digit($id), 404);

        $query = SavedQuery::query()->usableBy($this->userId($request), (string) $request->attributes->get(ResolveRole::LABEL))->whereKey((int) $id)->first();

        abort_unless($query instanceof SavedQuery, 404);

        return $query;
    }

    private function findOwned(Request $request, string $id): SavedQuery
    {
        $query = $this->findUsable($request, $id);

        abort_unless($query->isOwnedBy($this->userId($request)), 404);

        return $query;
    }
}
