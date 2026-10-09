<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use TacticalNinj4\DbExplorer\Drivers\Postgres\BackupTool;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use TacticalNinj4\DbExplorer\Jobs\Backup as BackupJob;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Role;
use Throwable;

/**
 * The backups API (PRD §7.12), admins only: the pg_dump preflight status
 * and the stored dumps of this label, plus dispatching a new one. A dump
 * downloads through the runs' signed URL like any result (S16); restoring
 * is deliberately not an API — pg_restore is a CLI job for the operator,
 * which the docs say plainly.
 */
class BackupController
{
    public function index(Request $request, DatabaseManager $db): JsonResponse
    {
        $this->authorize($request);

        $preflight = $this->preflight($request, $db);

        $disk = (string) config('dbexplorer.backup.disk', 'local');
        $label = (string) $request->attributes->get(ResolveRole::LABEL);
        $files = [];

        try {
            $storage = Storage::disk($disk);

            foreach ($storage->files("backups/{$label}") as $file) {
                $files[] = [
                    'name' => basename($file),
                    'bytes' => $storage->size($file),
                    'created_at' => date('c', $storage->lastModified($file)),
                ];
            }
        } catch (Throwable) {
            $files = [];
        }

        usort($files, fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return new JsonResponse(['data' => [
            'preflight' => $preflight,
            'keep' => (int) config('dbexplorer.backup.keep', 7),
            'backups' => $files,
        ]]);
    }

    /**
     * Queue a backup: 202 with the run, or 422 with the preflight's problem
     * (pg_dump missing, older than the server, database unreachable), so a
     * dump that can't work is never queued.
     */
    public function store(Request $request, DatabaseManager $db): JsonResponse
    {
        $this->authorize($request);

        $preflight = $this->preflight($request, $db);

        if ($preflight !== null) {
            return new JsonResponse(['error' => ['message' => $preflight]], 422);
        }

        $run = Run::queueFor($request, BackupJob::TYPE, []);
        BackupJob::dispatch($run->id);

        return new JsonResponse(['data' => $run->toApi()], 202);
    }

    /**
     * Null when pg_dump can dump this label's database, the problem otherwise.
     */
    private function preflight(Request $request, DatabaseManager $db): ?string
    {
        $tool = new BackupTool($db->connection((string) $request->attributes->get(ResolveRole::CONNECTION)), (string) config('dbexplorer.backup.pg_dump_path', 'pg_dump'));

        try {
            return $tool->preflight();
        } catch (Throwable) {
            return 'The database could not be reached for the pg_dump version check.';
        }
    }

    private function authorize(Request $request): void
    {
        abort_unless((string) $request->attributes->get(ResolveRole::ROLE) === Role::ADMIN, 403, 'Only admins can back the database up.');
    }
}
