<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDOException;
use RuntimeException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Exceptions\RunCancelled;
use TacticalNinj4\DbExplorer\Jobs\Concerns\TracksRun;
use TacticalNinj4\DbExplorer\Models\Run;

/**
 * The import job (PRD §7.6): loads an uploaded CSV into an existing table.
 *
 * Rows are parsed with fgetcsv, mapped per `meta.map` (null skips a CSV
 * column) and inserted through one prepared statement, in batches of
 * limits.import.batch rows — memory stays flat whatever the file size.
 * (The plan suggested COPY through PDO; PHP's pgsqlCopyFromArray quotes its
 * table argument itself, so neither a schema qualifier nor a column list
 * survives it, and a package import needs both.) Two modes, `meta.mode`:
 *
 * - `transaction` (default): one transaction around the whole file, so one
 *   bad row fails the run and rolls the import back completely;
 * - `skip`: every batch runs under a SAVEPOINT. A failing batch rolls back
 *   to its savepoint and retries row by row, so only the truly bad rows are
 *   left out, recorded in `meta.errors` (`{row, error}`, at most 1000) with
 *   `meta.skipped` counting them all.
 *
 * Identifiers were validated against the table's introspected columns at
 * dispatch; the job carries only the run id (S21). The editor floor was
 * checked at dispatch, and the role again here; the database grants are the
 * real boundary, as everywhere.
 */
final class Import implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksRun;

    public const TYPE = 'import';

    public const TRANSACTION = 'transaction';

    public const SKIP = 'skip';

    public const MAX_ERRORS = 1000;

    protected function process(Run $run, string $connection): void
    {
        $table = (array) ($run->meta['table'] ?? []);
        $schema = (string) ($table['schema'] ?? '');
        $name = (string) ($table['name'] ?? '');
        /** @var list<string|null> $map */
        $map = is_array($run->meta['map'] ?? null)
            ? array_values(array_map(fn (mixed $column): ?string => is_string($column) && $column !== '' ? $column : null, (array) $run->meta['map']))
            : [];
        $header = (bool) ($run->meta['header'] ?? true);
        $emptyAsNull = (bool) ($run->meta['empty_as_null'] ?? true);
        $skipMode = ($run->meta['mode'] ?? null) === self::SKIP;

        $batchSize = max(100, (int) config('dbexplorer.limits.import.batch', 5000));

        $columns = array_values(array_unique(array_values(array_filter($map, fn (?string $column): bool => $column !== null))));

        if ($columns === []) {
            throw new RuntimeException('The import maps no column.');
        }

        $tableRef = '"'.str_replace('"', '""', $schema).'"."'.str_replace('"', '""', $name).'"';
        $columnList = implode(', ', array_map(fn (string $column): string => '"'.str_replace('"', '""', $column).'"', $columns));
        $insertSql = 'INSERT INTO '.$tableRef.' ('.$columnList.') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')';

        $token = (string) ($run->meta['token'] ?? '');
        $path = Storage::disk((string) config('dbexplorer.storage.disk', 'local'))->path('imports/'.$token.'.csv');

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        try {
            if ($header && fgetcsv($handle, 0, ',', '"', '') === false) {
                throw new RuntimeException('The uploaded file has no rows to import.');
            }

            $errors = [];
            $inserted = 0;
            $skipped = 0;
            $rowNumber = 0;

            app(QueryRunner::class)->atomic($connection, (string) $run->role, function (Closure $statement) use ($connection, $handle, $map, $emptyAsNull, $skipMode, $batchSize, $insertSql, &$errors, &$inserted, &$skipped, &$rowNumber): void {
                $this->trackBackend($statement);

                $pdo = DB::connection($connection)->getPdo();
                $insert = $pdo->prepare($insertSql);

                /** @var list<list<scalar|null>> $batch */
                $batch = [];

                $flush = function (int $rowNumber) use (&$batch, &$inserted, &$skipped, &$errors, $statement, $insert, $skipMode): void {
                    if ($batch === []) {
                        return;
                    }

                    $firstRow = $rowNumber - count($batch) + 1;

                    if (! $skipMode) {
                        foreach ($batch as $values) {
                            $insert->execute($values);
                            $inserted++;
                        }

                        $batch = [];

                        return;
                    }

                    $statement('SAVEPOINT dbx_import_batch', []);

                    try {
                        $done = 0;

                        foreach ($batch as $values) {
                            $insert->execute($values);
                            $inserted++;
                            $done++;
                        }
                    } catch (PDOException) {
                        // The batch is half in; drop all of it and find the
                        // bad rows one by one.
                        $statement('ROLLBACK TO SAVEPOINT dbx_import_batch', []);
                        $inserted -= $done;

                        foreach ($batch as $offset => $values) {
                            $statement('SAVEPOINT dbx_import_row', []);

                            try {
                                $insert->execute($values);
                                $inserted++;
                            } catch (PDOException $rowError) {
                                $statement('ROLLBACK TO SAVEPOINT dbx_import_row', []);
                                $skipped++;

                                if (count($errors) < self::MAX_ERRORS) {
                                    $message = strtok($rowError->getMessage(), "\n");
                                    $state = (string) $rowError->getCode();

                                    $errors[] = [
                                        'row' => $firstRow + $offset,
                                        'error' => ($state !== '' ? $state.': ' : '').($message === false ? 'The row was rejected.' : $message),
                                    ];
                                }
                            }
                        }
                    }

                    $statement('RELEASE SAVEPOINT dbx_import_batch', []);
                    $batch = [];
                };

                while (is_array($row = fgetcsv($handle, 0, ',', '"', ''))) {
                    $rowNumber++;
                    $batch[] = $this->mappedValues($row, $map, $emptyAsNull);

                    if (count($batch) >= $batchSize) {
                        $flush($rowNumber);
                        $this->progress(0, ['inserted' => $inserted, 'skipped' => $skipped]);

                        if ($this->shouldStop()) {
                            throw new RunCancelled;
                        }
                    }
                }

                $flush($rowNumber);
            });

            $this->progress(100, ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Map a parsed CSV row to the insert's value order: null entries in the
     * map drop their CSV column.
     *
     * @param  list<mixed>  $row
     * @param  list<string|null>  $map
     * @return list<scalar|null>
     */
    private function mappedValues(array $row, array $map, bool $emptyAsNull): array
    {
        $result = [];

        foreach ($map as $index => $column) {
            if ($column === null) {
                continue;
            }

            $value = $row[$index] ?? null;

            if ($emptyAsNull && ($value === '' || $value === null)) {
                $value = null;
            }

            $result[] = is_scalar($value) || $value === null ? $value : (string) $value;
        }

        return $result;
    }
}
