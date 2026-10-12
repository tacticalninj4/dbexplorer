<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use TacticalNinj4\DbExplorer\Drivers\Postgres\BackupTool;
use TacticalNinj4\DbExplorer\Exceptions\RunCancelled;
use TacticalNinj4\DbExplorer\Exceptions\RunFailed;
use TacticalNinj4\DbExplorer\Jobs\Concerns\TracksRun;
use TacticalNinj4\DbExplorer\Models\Run;
use Throwable;

/**
 * A database backup (PRD §7.10): pg_dump in custom format, writing straight
 * onto the private backup disk as `backups/{label}/run-{id}.dump`,
 * with retention keeping the newest `backup.keep` per label. Restores are a
 * CLI matter — pg_restore against a target the operator chooses — and the
 * docs say so; the API never restores.
 *
 * Cancelling the run stops pg_dump and deletes the partial file.
 *
 * The run's result fields point at the stored dump, so the signed download
 * flow (S16) serves it to the run's owner. A failed preflight or dump fails
 * the run with the real cause (connection details redacted) as its error.
 */
final class Backup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksRun;

    public const TYPE = 'backup';

    /** How often a running dump checks whether its run was cancelled. */
    private const POLL_MICROSECONDS = 500000;

    protected function process(Run $run, string $connection): void
    {
        $tool = new BackupTool(DB::connection($connection), (string) config('dbexplorer.backup.pg_dump_path', 'pg_dump'));

        // The same check the API ran before queueing: things may have changed since.
        $problem = $tool->preflight();

        if ($problem !== null) {
            throw new RunFailed($problem);
        }

        $disk = (string) config('dbexplorer.backup.disk', 'local');
        $storage = Storage::disk($disk);
        $directory = 'backups/'.(string) $run->label;
        $relative = $directory.'/run-'.$run->id.'.dump';

        $storage->makeDirectory($directory);

        // pg_dump writes the archive itself, straight onto the backup disk:
        // no temp file to leak or clean up. Only filesystem disks have a
        // local path; a cloud disk is the wrong target for something that
        // restores through the CLI anyway.
        $target = $storage->path($relative);

        if ($target === '' || ! is_dir(dirname($target))) {
            throw new RunFailed("The backup disk [{$disk}] has no local path for pg_dump to write to. Point dbexplorer.backup.disk at a filesystem disk.");
        }

        /** @var list<string> $schemas */
        $schemas = array_values(array_filter((array) ($run->meta['schemas'] ?? []), 'is_string'));

        $process = $tool->start(array_merge($tool->command('custom', $schemas), ['--file', $target]), (float) $this->timeout);

        try {
            // The run's backend pid is this job's own idle connection, so a
            // cancel's pg_cancel_backend never reaches pg_dump: watch for the
            // cancel flag instead, and stop pg_dump (and so its session).
            while ($process->isRunning()) {
                if ($this->shouldStop()) {
                    $process->stop(5);

                    throw new RunCancelled;
                }

                $process->checkTimeout();
                usleep(self::POLL_MICROSECONDS);
            }
        } catch (Throwable $e) {
            // A cancelled or timed-out dump is partial: never leave it looking like a backup.
            $storage->delete($relative);

            throw $e;
        }

        if (! $process->isSuccessful()) {
            $storage->delete($relative);

            throw new RunFailed($tool->describeFailure($process));
        }

        $bytes = (int) $storage->size($relative);

        Run::query()->whereKey($run->id)->update(['result_disk' => $disk, 'result_path' => $relative]);

        $this->retain((string) $run->label, $disk);

        $this->progress(100, ['bytes' => $bytes, 'format' => 'custom']);
    }

    /**
     * Keep only the newest `backup.keep` dumps of the label.
     */
    private function retain(string $label, string $disk): void
    {
        $keep = (int) config('dbexplorer.backup.keep', 7);

        if ($keep < 1) {
            return;
        }

        $storage = Storage::disk($disk);
        $files = $storage->files("backups/{$label}");

        if (count($files) <= $keep) {
            return;
        }

        $byAge = [];

        foreach ($files as $file) {
            $byAge[$file] = $storage->lastModified($file);
        }

        arsort($byAge);

        foreach (array_slice(array_keys($byAge), $keep) as $stale) {
            $storage->delete($stale);
        }
    }
}
