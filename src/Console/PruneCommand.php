<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use TacticalNinj4\DbExplorer\Audit\AuditLog;

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
        // M3.3 adds expired files (storage.file_ttl_hours), runs and query history here.
        $this->pruneAudit($audit);

        return self::SUCCESS;
    }

    private function pruneAudit(AuditLog $audit): void
    {
        $days = config('dbexplorer.audit.retention_days');

        if (! is_numeric($days) || (int) $days <= 0) {
            $this->line('Audit log: retention disabled, nothing pruned.');

            return;
        }

        $deleted = $audit->prune(Date::now()->subDays((int) $days));

        $this->info("Audit log: pruned {$deleted} entries older than {$days} days.");
    }
}
