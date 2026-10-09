<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Exceptions\ScriptParseException;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Jobs\RunScript;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;
use TacticalNinj4\DbExplorer\Sql\Classifier;
use TacticalNinj4\DbExplorer\Sql\ScriptSplitter;

/**
 * The script runner API (PRD §7.7): queue a multi-statement script as a run.
 *
 * The splitter's strict mode refuses a script it can't split with certainty
 * (unterminated strings, unbalanced parentheses, psql meta-commands, inline
 * COPY data) with a 422 pointing at the line, so nothing runs half a
 * statement. Every statement then runs through its own native prepare (S8).
 *
 * Gate, in order, all UX only (S11) — the editor's database grants are the
 * boundary, and the job re-checks the role when it starts:
 *
 * 1. The editor role or higher (viewers stay read-only, S8).
 * 2. Admin when the classifier finds DDL in the script.
 * 3. A script that isn't pure reads asks for `confirm: true` (409), like the
 *    SQL editor; `destructive` also needs a recent password confirmation (S5).
 */
class ScriptController
{
    /**
     * Queue `sql` in `mode` ('transaction' or 'step') as a `script` run and
     * answer 202 with it. Nothing runs in the request.
     *
     * @throws ValidationException
     */
    public function __invoke(Request $request, PasswordConfirmation $confirmation): JsonResponse
    {
        $input = $request->validate([
            'sql' => ['required', 'string', 'max:'.(int) config('dbexplorer.limits.sql_length', 100000)],
            'mode' => ['sometimes', 'in:'.RunScript::TRANSACTION.','.RunScript::STEP],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        $sql = (string) $input['sql'];
        $role = (string) $request->attributes->get(ResolveRole::ROLE);

        abort_unless(Role::atLeast($role, Role::EDITOR), 403, 'The script runner needs the editor role or higher.');

        try {
            $statements = ScriptSplitter::split($sql);
        } catch (ScriptParseException $exception) {
            throw ValidationException::withMessages(['sql' => [$exception->getMessage()]]);
        }

        abort_if($statements === [], 422, 'There is no statement to run.');

        if ($role !== Role::ADMIN && Classifier::isDdl($sql)) {
            abort(403, 'Scripts containing DDL (CREATE, ALTER, DROP, TRUNCATE, GRANT, ...) need the admin role.');
        }

        $classification = Classifier::classify($sql);

        if ($classification !== Classifier::READ && ! (bool) ($input['confirm'] ?? false)) {
            return new JsonResponse(['error' => [
                'message' => "This script looks like {$classification} SQL. Confirm to run it.",
                'code' => 'confirmation_required',
                'classification' => $classification,
            ]], 409);
        }

        if ($classification === Classifier::DESTRUCTIVE) {
            $confirmation->ensureConfirmed($request);
        }

        $run = Run::queueFor($request, RunScript::TYPE, [
            'sql' => $sql,
            'mode' => (string) ($input['mode'] ?? RunScript::TRANSACTION),
            'statement_count' => count($statements),
        ]);

        RunScript::dispatch($run->id);

        return new JsonResponse(['data' => $run->toApi()], 202);
    }
}
