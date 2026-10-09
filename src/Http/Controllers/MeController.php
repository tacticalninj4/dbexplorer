<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\Exceptions\ConnectionNotAllowedException;
use TacticalNinj4\DbExplorer\Role;

class MeController
{
    /**
     * The current user's name and the connection labels they can use, with
     * their role on each. Connection config never leaves the server (S7).
     *
     * `warnings` holds one message per label the user can use where two or
     * more roles map to the same Laravel connection (M2.5, PRD §13), naming
     * the label and roles only: the database can't tell those roles apart.
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
            'warnings' => $warnings,
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
}
