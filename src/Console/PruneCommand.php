<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use TacticalNinj4\DbExplorer\Audit\AuditLog;
use TacticalNinj4\DbExplorer\Models\QueryHistory;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use Throwable;

class PruneCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dbx:prune';

    /**
     * @var string
     */
    protected $description = 'Delete DbExplorer data older than its configured retention';

    public function handle(AuditLog $audit): int
    {
        $this->pruneFiles();
        $this->pruneRuns();
        $this->pruneHistory();
        $this->pruneAudit($audit);

        return self::SUCCESS;
    }

    /**
     * Expired result files (storage.file_ttl_hours) on the storage disk: a
     * run's downloadable result outlives the run row, but not for long.
     */
    private function pruneFiles(): void
    {
        $hours = config('dbexplorer.storage.file_ttl_hours');

        if (! is_numeric($hours) || (int) $hours <= 0) {
            $this->line('Files: retention disabled, nothing pruned.');

            return;
        }

        $disk = config('dbexplorer.storage.disk');
        $disk = is_string($disk) && $disk !== '' ? $disk : 'local';
        $cutoff = Date::now()->subHours((int) $hours)->getTimestamp();
        $deleted = 0;

        try {
            $storage = Storage::disk($disk);

            foreach (['exports', 'imports'] as $directory) {
                foreach ($storage->files($directory) as $file) {
                    if ($storage->lastModified($file) < $cutoff && $storage->delete($file)) {
                        $deleted++;
                    }
                }
            }
        } catch (Throwable) {
            $this->error("Files: the storage disk [{$disk}] could not be read.");

            return;
        }

        $this->info("Files: pruned {$deleted} expired result files older than {$hours} hours.");
    }

    /**
     * Finished runs older than audit.retention_days. Runs still queued or
     * running are never touched, whatever their age. Rows go through the
     * query builder: no model events, and their result files were pruned (or
     * kept) on their own schedule above.
     */
    private function pruneRuns(): void
    {
        $days = $this->retentionDays();

        if ($days === null) {
            $this->line('Runs: retention disabled, nothing pruned.');

            return;
        }

        $deleted = Run::query()
            ->whereIn('status', RunStatus::FINISHED)
            ->where('created_at', '<', Date::now()->subDays($days))
            ->delete();

        $this->info("Runs: pruned {$deleted} finished runs older than {$days} days.");
    }

    private function pruneHistory(): void
    {
        $days = $this->retentionDays();

        if ($days === null) {
            $this->line('History: retention disabled, nothing pruned.');

            return;
        }

        $deleted = QueryHistory::query()
            ->where('created_at', '<', Date::now()->subDays($days))
            ->delete();

        $this->info("History: pruned {$deleted} history rows older than {$days} days.");
    }

    private function pruneAudit(AuditLog $audit): void
    {
        $days = $this->retentionDays();

        if ($days === null) {
            $this->line('Audit log: retention disabled, nothing pruned.');

            return;
        }

        $deleted = $audit->prune(Date::now()->subDays($days));

        $this->info("Audit log: pruned {$deleted} entries older than {$days} days.");
    }

    /**
     * The package-wide retention, from audit.retention_days, or null when
     * disabled.
     */
    private function retentionDays(): ?int
    {
        $days = config('dbexplorer.audit.retention_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }
}
