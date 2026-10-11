<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\DdlBuilder;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Jobs\RunScript;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;

/**
 * The schema editor API (PRD §7.4), admins only: turn one structured
 * operation (see {@see DdlBuilder}) into SQL to show, then run it.
 */
class DdlController
{
    /**
     * The statements for `op`, nothing runs: `{data: {statements: [{sql,
     * classification, transactional, background, raw_expressions}], migration}}`, where
     * `migration` is a best-effort Laravel `Schema::` snippet or null. An
     * invalid operation (including an unknown type) is a 422.
     *
     * @throws ValidationException
     */
    public function preview(Request $request, DatabaseManager $db): JsonResponse
    {
        $builder = $this->builder($request, $db);
        $op = $this->operation($request);

        return new JsonResponse(['data' => [
            'statements' => $builder->build($op),
            'migration' => $builder->toMigrationSnippet($op),
        ]]);
    }

    /**
     * Build `op` and run its statements in one transaction through the
     * QueryRunner (role timeout, hidden-object check, audit, session reset).
     * Destructive statements need a recent password confirmation (S5, else
     * 423). A database error is a 422 with the failing statement's index;
     * nothing was applied.
     *
     * Long work never runs inside the request (PRD §7.5): when any statement
     * is marked `background` (CREATE or DROP INDEX CONCURRENTLY, and every
     * REFRESH MATERIALIZED VIEW, plain or CONCURRENTLY), the operation is
     * queued instead as a script run (202 with `run`, M3.5). The run's job
     * tracks the backend pid before the statement (cancel), applies
     * limits.background.timeout, writes the `script` audit entry and fires
     * DdlExecuted on success. It runs in step mode, outside any transaction,
     * when a statement can't run in one (CONCURRENTLY on an index), and in
     * one transaction otherwise.
     *
     * @throws ValidationException
     * @throws QueryFailed
     */
    public function execute(Request $request, DatabaseManager $db, QueryRunner $runner, PasswordConfirmation $confirmation): JsonResponse
    {
        $statements = $this->builder($request, $db)->build($this->operation($request));

        if (in_array(DdlBuilder::DESTRUCTIVE, array_column($statements, 'classification'), true)) {
            $confirmation->ensureConfirmed($request);
        }

        if (in_array(true, array_column($statements, 'background'), true)) {
            $run = Run::queueFor($request, RunScript::TYPE, [
                'sql' => implode(";\n", array_column($statements, 'sql')),
                'mode' => in_array(false, array_column($statements, 'transactional'), true) ? RunScript::STEP : RunScript::TRANSACTION,
                'source' => 'ddl',
            ]);

            RunScript::dispatch($run->id);

            return new JsonResponse(['data' => ['statements' => $statements, 'run' => $run->toApi()]], 202);
        }

        $result = $runner->runStatements(
            (string) $request->attributes->get(ResolveRole::CONNECTION),
            array_column($statements, 'sql'),
            (string) $request->attributes->get(ResolveRole::ROLE),
        );

        return new JsonResponse(['data' => [
            'statements' => $statements,
            'duration_ms' => $result->durationMs,
            'notices' => $result->notices,
            'notices_supported' => $result->noticesSupported,
        ]]);
    }

    /**
     * A builder that validates types on the request's connection. Admins only.
     */
    protected function builder(Request $request, DatabaseManager $db): DdlBuilder
    {
        abort_unless($request->attributes->get(ResolveRole::ROLE) === Role::ADMIN, 403, 'Only admins can change the schema.');

        return DdlBuilder::forConnection($db->connection((string) $request->attributes->get(ResolveRole::CONNECTION)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function operation(Request $request): array
    {
        /** @var array{op: array<string, mixed>} $input */
        $input = $request->validate(['op' => ['required', 'array']]);

        return $input['op'];
    }
}
