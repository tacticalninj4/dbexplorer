<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\Exceptions\ConnectionNotAllowedException;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Role;

class MeController
{
    /**
     * The current user's name and the connection labels they can use, with
     * their role on each. Connection config never leaves the server (S7).
     *
     * `warnings` holds, per label the user can use where two or more roles
     * map to the same Laravel connection, a message naming the label and
     * roles only (M2.5, PRD §13): the database can't tell those roles apart.
     * Background-work health warnings are appended (M3.1, PRD §7.5).
     */
    public function __invoke(Request $request, DbExplorer $explorer): JsonResponse
    {
        $user = $request->user();
        $name = data_get($user, 'name');
        $connections = [];
        $warnings = [];

        foreach ($explorer->labels() as $label) {
            $role = $explorer->roleFor($user, $label);

            if ($role !== null) {
                $connections[] = ['label' => $label, 'role' => $role];
                array_push($warnings, ...$this->sharedConnectionWarnings($explorer, $label));
            }
        }

        return new JsonResponse([
            'user' => ['name' => is_string($name) ? $name : ''],
            'connections' => $connections,
            'warnings' => array_merge($warnings, $this->queueWarnings()),
        ]);
    }

    /**
     * @return list<string>
     */
    protected function sharedConnectionWarnings(DbExplorer $explorer, string $label): array
    {
        $roles = [];

        foreach (Role::ALL as $role) {
            try {
                $roles[$explorer->connectionFor($label, $role)][] = $role;
            } catch (ConnectionNotAllowedException) {
                // The role has no mapping on this label.
            }
        }

        $warnings = [];

        foreach ($roles as $shared) {
            if (count($shared) > 1) {
                $last = array_pop($shared);
                $warnings[] = sprintf(
                    'On "%s", the %s and %s roles use the same database connection, so the database cannot enforce their different permissions. Map each role to its own connection and database login.',
                    $label,
                    implode(', ', $shared),
                    $last,
                );
            }
        }

        return $warnings;
    }

    /**
     * Background work health (PRD §7.5): a `sync` queue runs jobs inside the
     * request, and a run queued for over 5 minutes means no worker is running.
     *
     * @return list<string>
     */
    protected function queueWarnings(): array
    {
        $connection = config('dbexplorer.queue.connection') ?? config('queue.default');

        if (config("queue.connections.{$connection}.driver") === 'sync') {
            return ['The queue driver is "sync": background work runs inside the request and blocks it until done.'];
        }

        $stuck = Run::query()
            ->where('status', RunStatus::QUEUED)
            ->where('created_at', '<', Date::now()->subMinutes(5))
            ->exists();

        return $stuck ? ['A background run has been queued for over 5 minutes: no queue worker seems to be running.'] : [];
    }
}
