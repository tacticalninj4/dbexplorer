<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use TacticalNinj4\DbExplorer\Export\ExportWriter;
use TacticalNinj4\DbExplorer\Jobs\Concerns\ExportsToDisk;
use TacticalNinj4\DbExplorer\Jobs\Concerns\TracksRun;
use TacticalNinj4\DbExplorer\Models\Run;

/**
 * A background query (PRD §7.3, §7.5): the SQL editor's "run in background",
 * for SELECTs too slow or large for the request. The result is written to a
 * JSON Lines file on the run's disk, downloadable once the run succeeds.
 * `meta.sql` and `meta.bindings` come from the request; the job carries only
 * the run id (S21).
 */
final class RunQuery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksRun;
    use ExportsToDisk;

    public const TYPE = 'query';

    protected function process(Run $run, string $connection): void
    {
        $bindings = $run->meta['bindings'] ?? [];

        $this->exportToDisk(
            $run,
            $connection,
            (string) ($run->meta['sql'] ?? ''),
            is_array($bindings) ? $bindings : [],
            ExportWriter::JSONL,
        );
    }
}
