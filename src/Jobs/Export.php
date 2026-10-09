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
 * An export (PRD §7.6): a table, view or SELECT result streamed to CSV, JSON
 * Lines or SQL INSERTs on the run's disk. `meta.sql` is the effective query
 * (the controller builds `SELECT * FROM …` for a table), `meta.format` one of
 * {@see ExportWriter::FORMATS}, and `meta.table` — present for the sql
 * format — the `{schema, name}` the INSERTs target. Viewers can export too
 * (D4): masking is applied in the file (S14). The job carries only the run
 * id (S21).
 */
final class Export implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksRun;
    use ExportsToDisk;

    public const TYPE = 'export';

    protected function process(Run $run, string $connection): void
    {
        $bindings = $run->meta['bindings'] ?? [];
        $table = $run->meta['table'] ?? null;

        $this->exportToDisk(
            $run,
            $connection,
            (string) ($run->meta['sql'] ?? ''),
            is_array($bindings) ? $bindings : [],
            (string) ($run->meta['format'] ?? ExportWriter::CSV),
            is_array($table) ? ['schema' => (string) ($table['schema'] ?? ''), 'name' => (string) ($table['name'] ?? '')] : null,
        );
    }
}
