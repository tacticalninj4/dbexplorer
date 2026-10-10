<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Sessions;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;

/**
 * The active sessions API (PRD §7.11, M4.4), admins only: list this
 * database's backends, cancel a running statement, or terminate a session
 * outright. Terminate is destructive (S5): a recent password confirmation,
 * else 423. Both signals are audited.
 */
class SessionsController
{
    public function index(Request $request, DatabaseManager $db): JsonResponse
    {
        $this->authorize($request);

        return new JsonResponse(['data' => $this->sessions($request, $db)->list()]);
    }

    public function cancel(Request $request, DbExplorer $explorer, DatabaseManager $db, string $label, string $pid): JsonResponse
    {
        $this->authorize($request);

        $signalled = $this->sessions($request, $db)->cancel($this->pid($pid));

        $explorer->audit(['action' => 'session.cancel', 'meta' => ['pid' => (int) $pid, 'existed' => $signalled]]);

        return $signalled
            ? new JsonResponse([], 204)
            : new JsonResponse(['error' => ['message' => 'No session with that pid belongs to this database login.']], 404);
    }

    public function terminate(Request $request, DbExplorer $explorer, DatabaseManager $db, PasswordConfirmation $confirmation, string $label, string $pid): JsonResponse
    {
        $this->authorize($request);

        $confirmation->ensureConfirmed($request);

        $signalled = $this->sessions($request, $db)->terminate($this->pid($pid));

        $explorer->audit(['action' => 'session.terminate', 'meta' => ['pid' => (int) $pid, 'existed' => $signalled]]);

        return $signalled
            ? new JsonResponse([], 204)
            : new JsonResponse(['error' => ['message' => 'No session with that pid belongs to this database login.']], 404);
    }

    private function sessions(Request $request, DatabaseManager $db): Sessions
    {
        return new Sessions($db->connection((string) $request->attributes->get(ResolveRole::CONNECTION)));
    }

    /**
     * Admins only, per label.
     */
    private function authorize(Request $request): void
    {
        abort_unless((string) $request->attributes->get(ResolveRole::ROLE) === Role::ADMIN, 403, 'Only admins can see active sessions.');
    }

    private function pid(string $pid): int
    {
        abort_unless(ctype_digit($pid), 404);

        return (int) $pid;
    }
}
