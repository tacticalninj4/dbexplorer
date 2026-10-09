<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Export;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\RowsQuery;

/**
 * Writes a streamed query result to a file, format by format (PRD §7.6).
 *
 * Rows arrive in cursor chunks (QueryRunner::stream()) and go straight to a
 * `php://temp` stream, which keeps them in memory only until 2 MB and then
 * spills to disk: peak memory stays flat whatever the result size. The
 * caller stores the stream on the run's disk and closes it.
 *
 * Values are rendered cell by cell: NULL stays NULL-shaped per format,
 * booleans become t/f (csv) or TRUE/FALSE (sql), arrays and other non-scalars
 * become JSON. Anything string-shaped goes through the `$quote` callback —
 * the caller passes the connection's PDO::quote — so SQL INSERT output can't
 * be broken by quotes or escapes in the data. Table and column names are
 * quoted identifiers, `"` doubled (S12), whatever characters they hold.
 */
final class ExportWriter
{
    public const CSV = 'csv';

    public const JSONL = 'jsonl';

    public const SQL = 'sql';

    public const FORMATS = [self::CSV, self::JSONL, self::SQL];

    /** @var resource|null */
    private $handle;

    private int $rows = 0;

    private int $bytes = 0;

    private bool $header = false;

    /**
     * @param  Closure(string): string  $quote  quotes a string literal for the sql format
     * @param  array{schema: string, name: string}|null  $table  INSERT target for the sql format
     */
    public function __construct(
        private string $format,
        private Closure $quote,
        private ?array $table = null,
    ) {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unknown export format [{$format}].");
        }

        if ($format === self::SQL && $table === null) {
            throw new InvalidArgumentException('The sql format needs a table to write INSERTs for.');
        }
    }

    public static function extension(string $format): string
    {
        return match ($format) {
            self::CSV => 'csv',
            self::JSONL => 'jsonl',
            self::SQL => 'sql',
            default => throw new InvalidArgumentException("Unknown export format [{$format}]."),
        };
    }

    /**
     * Open the temp stream. No header yet: column names only arrive with the
     * first chunk.
     */
    public function open(): void
    {
        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temp stream for the export.');
        }

        $this->handle = $handle;
    }

    /**
     * Write one chunk. `$columns` arrives with every chunk; only the first is
     * used, for the csv header.
     *
     * @param  list<array{name: string, type: string, masked: bool}>  $columns
     * @param  list<list<mixed>>  $rows
     */
    public function write(array $columns, array $rows): void
    {
        $this->assertOpen();

        if ($this->format === self::CSV && ! $this->header) {
            $this->line(array_map(fn (array $column): string => $column['name'], $columns), ',');
            $this->header = true;
        }

        foreach ($rows as $row) {
            $this->rows++;

            if ($this->format === self::CSV) {
                $this->line(array_map([$this, 'cell'], $row), ',');
            } elseif ($this->format === self::JSONL) {
                // Native types, not the csv cells' string shapes: a jsonl line
                // round-trips through json_decode with its numbers and nulls.
                $this->raw((string) json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE));
                $this->raw("\n");
            } else {
                $this->insert($columns, $row);
            }
        }
    }

    /**
     * The stream, positioned at the start, for Storage::put(). Still open:
     * call close() once stored.
     *
     * @return resource
     */
    public function stream(): mixed
    {
        $this->assertOpen();

        rewind($this->handle);

        return $this->handle;
    }

    /**
     * Close the stream and report what was written.
     *
     * @return array{rows: int, bytes: int}
     */
    public function close(): array
    {
        $this->assertOpen();

        $stats = fstat($this->handle);
        $this->bytes = is_array($stats) ? (int) $stats['size'] : 0;

        fclose($this->handle);
        $this->handle = null;

        return ['rows' => $this->rows, 'bytes' => $this->bytes];
    }

    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * One csv line through fputcsv, so quoting, escapes and newlines inside
     * values follow PHP's rules (RFC 4180 with the escape character disabled).
     *
     * @param  list<string|null>  $values
     */
    private function line(array $values, string $separator): void
    {
        fputcsv($this->handle, $values, $separator, '"', '');
    }

    /**
     * One INSERT statement for the sql format.
     *
     * @param  list<array{name: string, type: string, masked: bool}>  $columns
     * @param  list<mixed>  $row
     */
    private function insert(array $columns, array $row): void
    {
        $table = $this->table;

        $names = implode(', ', array_map(fn (array $column): string => RowsQuery::quoteIdentifier($column['name']), $columns));
        $values = implode(', ', array_map([$this, 'literal'], $row));

        $this->raw('INSERT INTO '.RowsQuery::quoteIdentifier($table['schema']).'.'.RowsQuery::quoteIdentifier($table['name']).' ('.$names.') VALUES ('.$values.");\n");
    }

    /**
     * A cell as csv and jsonl render it: NULL empty (csv) or null (jsonl),
     * booleans as t/f, everything else its string shape.
     */
    private function cell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 't' : 'f';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * A literal for the sql format: NULL, TRUE/FALSE, numbers bare, strings
     * through the caller's quote.
     */
    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

        return ($this->quote)($text);
    }

    private function raw(string $bytes): void
    {
        fwrite($this->handle, $bytes);
    }

    private function assertOpen(): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('The export stream is not open.');
        }
    }
}
