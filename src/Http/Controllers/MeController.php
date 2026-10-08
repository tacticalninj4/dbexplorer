<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TacticalNinj4\DbExplorer\DbExplorer;

class MeController
{
    /**
     * The current user's name and the connection labels they can use, with
     * their role on each. Connection config never leaves the server (S7).
     */
    public function __invoke(Request $request, DbExplorer $explorer): JsonResponse
    {
        $user = $request->user();
        $name = data_get($user, 'name');
        $connections = [];

        foreach ($explorer->labels() as $label) {
            $role = $explorer->roleFor($user, $label);

            if ($role !== null) {
                $connections[] = ['label' => $label, 'role' => $role];
            }
        }

        return new JsonResponse([
            'user' => ['name' => is_string($name) ? $name : ''],
            'connections' => $connections,
            'warnings' => [],
        ]);
    }
}
