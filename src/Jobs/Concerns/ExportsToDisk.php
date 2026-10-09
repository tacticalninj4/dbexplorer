<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Jobs\Concerns;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Exceptions\RunCancelled;
use TacticalNinj4\DbExplorer\Export\ExportWriter;
use TacticalNinj4\DbExplorer\Jobs\Export;
use TacticalNinj4\DbExplorer\Jobs\RunQuery;
use TacticalNinj4\DbExplorer\Models\Run;

/**
 * The shared body of the jobs that stream a query's rows to a file on the
 * run's disk (PRD §7.5–7.6): a background query ({@see RunQuery})
 * and an export ({@see Export}).
 *
 * Compose with TracksRun, whose process() contract this runs under: the run's
 * `meta.sql` (plus `meta.bindings`) is streamed in cursor chunks, masked
 * (S14 — exports never unmask), through an {@see ExportWriter} into a
 * php://temp stream, then stored as `exports/run-{id}.{format}` on the
 * configured `storage.disk`. The run's result_disk/result_path are set only
 * once the file is stored, so a cancelled or failed run has no result to
 * download. Cancellation is checked between chunks: the temp stream is
 * discarded and nothing is stored.
 */
trait ExportsToDisk
{
    /**
     * @param  array<int|string, scalar|null>  $bindings
     * @param  array{schema: string, name: string}|null  $table
     */
    protected function exportToDisk(Run $run, string $connection, string $sql, array $bindings, string $format, ?array $table = null): void
    {
        $runner = app(QueryRunner::class);
        $writer = new ExportWriter($format, fn (string $value): string => $runner->quote($connection, $value), $table);
        $writer->open();

        $result = $runner->stream($connection, $sql, $bindings, (string) $run->role, function (array $columns, array $rows) use ($writer): void {
            $writer->write($columns, $rows);

            if ($this->shouldStop()) {
                throw new RunCancelled;
            }
        });

        $disk = config('dbexplorer.storage.disk');
        $disk = is_string($disk) && $disk !== '' ? $disk : 'local';
        $path = 'exports/run-'.$run->id.'.'.ExportWriter::extension($format);

        if (! Storage::disk($disk)->put($path, $writer->stream())) {
            throw new RuntimeException("The export could not be stored on disk [{$disk}].");
        }

        $stats = $writer->close();

        Run::query()->whereKey($run->id)->update(['result_disk' => $disk, 'result_path' => $path]);

        $this->progress(100, ['rows' => $result['rows'], 'bytes' => $stats['bytes'], 'columns' => count($result['columns'])]);
    }
}
