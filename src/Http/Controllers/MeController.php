<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;

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
            'warnings' => $this->queueWarnings(),
        ]);
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
