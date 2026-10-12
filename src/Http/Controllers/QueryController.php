<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Jobs\RunQuery;
use TacticalNinj4\DbExplorer\Models\QueryHistory;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;
use TacticalNinj4\DbExplorer\Sql\Classifier;

/**
 * The SQL editor API (PRD §7.3): run one statement through the QueryRunner,
 * and list the user's own query history on a connection.
 */
class QueryController
{
    public const HISTORY_PER_PAGE = 50;

    /** The 403 for a viewer's DML (F41), shared with saved queries. */
    public const VIEWER_DML = "Viewers can't change data (INSERT, UPDATE, DELETE, MERGE, CALL, SELECT ... FOR UPDATE).";

    /**
     * Run `sql` with optional `bindings` (all positional or all named), as
     * `explain` 'plain' or 'analyze', or `unmask`ed (admins only, audited).
     * The runner enforces the role's transaction mode, timeout and row cap
     * and audits every run; a database error is a 422 `{error: {message,
     * sqlstate, position}}`.
     *
     * Before running (M2.2), unless `explain` is set, the classifier's verdict
     * gates the request, for UX only (S11): the database grants and the
     * viewer's read-only transaction are the real boundary.
     *
     * 1. DML from a viewer: 403, also with `background` (F41). That is `dml`,
     *    and destructive DML (UPDATE or DELETE without WHERE), see
     *    Classifier::isDml(): the viewer's read-only transaction would refuse
     *    it anyway, so asking to confirm first would mislead. DDL from a
     *    non-admin: 403. That is `ddl`, and destructive DDL (DROP, TRUNCATE,
     *    ALTER … DROP), see Classifier::isDdl(). An editor's destructive DML
     *    goes on to steps 2 and 3.
     * 2. Anything but `read` (so `unknown` too: the classifier can't vouch that
     *    it only reads) without `confirm: true`: 409 `{error: {message, code:
     *    'confirmation_required', classification}}`, nothing runs. The client
     *    shows the classification, then re-sends with `confirm: true`.
     * 3. `destructive` without a recent password confirmation (S5): 423.
     *
     * @throws QueryFailed
     */
    public function run(Request $request, QueryRunner $runner, PasswordConfirmation $confirmation): JsonResponse
    {
        $input = $request->validate([
            'sql' => ['required', 'string', 'max:'.(int) config('dbexplorer.limits.sql_length', 100000)],
            'bindings' => ['sometimes', 'array', 'max:1000', function (string $attribute, mixed $value, Closure $fail): void {
                $keys = is_array($value) ? array_keys($value) : [];
                $named = count(array_filter($keys, 'is_string'));

                if ($named !== 0 && $named !== count($keys)) {
                    $fail('The bindings must be all positional or all named.');
                }
            }],
            'bindings.*' => [function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && ! is_scalar($value)) {
                    $fail('Each binding must be a string, number, boolean or null.');
                }
            }],
            'explain' => ['sometimes', 'nullable', 'in:'.QueryRunner::EXPLAIN_PLAIN.','.QueryRunner::EXPLAIN_ANALYZE],
            'unmask' => ['sometimes', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
            'background' => ['sometimes', 'boolean'],
        ]);

        $role = (string) $request->attributes->get(ResolveRole::ROLE);
        $unmask = (bool) ($input['unmask'] ?? false);

        abort_if($unmask && $role !== Role::ADMIN, 403, 'Only admins can unmask columns.');

        if (($input['explain'] ?? null) === null) {
            $classification = Classifier::classify((string) $input['sql']);

            abort_if($role === Role::VIEWER && Classifier::isDml((string) $input['sql']), 403, self::VIEWER_DML);

            if ((bool) ($input['background'] ?? false)) {
                // A background run streams to a file: only reads qualify, and
                // no explain, unmask or confirmation applies (PRD §7.3, §7.5).
                if ($classification !== Classifier::READ) {
                    throw ValidationException::withMessages(['background' => ['Only SELECT-style queries can run in the background.']]);
                }

                /** @var array<int|string, scalar|null> $bindings */
                $bindings = $input['bindings'] ?? [];

                $run = Run::queueFor($request, RunQuery::TYPE, [
                    'sql' => (string) $input['sql'],
                    'bindings' => $bindings,
                ]);

                RunQuery::dispatch($run->id);

                return new JsonResponse(['data' => $run->toApi()], 202);
            }

            abort_if($role !== Role::ADMIN && Classifier::isDdl((string) $input['sql']), 403, 'Only admins can run DDL (CREATE, ALTER, DROP, TRUNCATE, GRANT, ...).');

            if ($classification !== Classifier::READ && ! (bool) ($input['confirm'] ?? false)) {
                return new JsonResponse(['error' => [
                    'message' => "This statement looks like {$classification} SQL. Confirm to run it.",
                    'code' => 'confirmation_required',
                    'classification' => $classification,
                ]], 409);
            }

            if ($classification === Classifier::DESTRUCTIVE) {
                $confirmation->ensureConfirmed($request);
            }
        }

        /** @var array<int|string, scalar|null> $bindings */
        $bindings = $input['bindings'] ?? [];

        $result = $runner->run(
            (string) $request->attributes->get(ResolveRole::CONNECTION),
            (string) $input['sql'],
            $bindings,
            $role,
            ['explain' => $input['explain'] ?? null, 'unmask' => $unmask],
        );

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
            'explain' => $result->explain,
            'plan' => $result->plan,
        ]]);
    }

    /**
     * The current user's queries on this label, newest first, `page` by
     * page. Guests have no history.
     */
    public function history(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', '1'));
        $id = $request->user()?->getAuthIdentifier();

        $items = ! is_scalar($id) ? [] : QueryHistory::query()
            ->where('user_id', (string) $id)
            ->where('label', (string) $request->attributes->get(ResolveRole::LABEL))
            ->orderByDesc('id')
            ->offset(($page - 1) * self::HISTORY_PER_PAGE)
            ->limit(self::HISTORY_PER_PAGE + 1)
            ->get(['id', 'role', 'sql', 'classification', 'duration_ms', 'row_count', 'outcome', 'error', 'created_at'])
            ->map(fn (QueryHistory $entry): array => [
                'id' => $entry->id,
                'role' => $entry->role,
                'sql' => $entry->sql,
                'classification' => $entry->classification,
                'duration_ms' => $entry->duration_ms,
                'row_count' => $entry->row_count,
                'outcome' => $entry->outcome,
                'error' => $entry->error,
                'created_at' => $entry->created_at->toIso8601String(),
            ])
            ->all();

        return new JsonResponse([
            'data' => array_slice($items, 0, self::HISTORY_PER_PAGE),
            'meta' => ['page' => $page, 'per_page' => self::HISTORY_PER_PAGE, 'has_more' => count($items) > self::HISTORY_PER_PAGE],
        ]);
    }
}
