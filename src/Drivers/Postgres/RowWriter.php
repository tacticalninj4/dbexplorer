<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Drivers\Postgres;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Events\RowChanged;
use TacticalNinj4\DbExplorer\Exceptions\ObjectNotFoundException;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Exceptions\RowConflict;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Security\Masker;
use TacticalNinj4\DbExplorer\Sql\Classifier;

/**
 * Inline row insert, update and delete for the data grid (PRD §7.2, M2.3),
 * on tables with a primary key only. Editors and admins only.
 *
 * S12: every identifier is a schema, table or column name the Introspector
 * returned for the role's login, matched exactly and quoted by
 * DdlBuilder::quoteIdentifier(). Every value is bound and cast to the
 * column's introspected type without its modifier (`CAST(? AS bpchar)`),
 * so the column's own assignment rules still apply: a too-long varchar is
 * an error, never silently cut. Anything else is a 422 before any SQL runs.
 *
 * Values travel as JSON strings, numbers, booleans or null, in PostgreSQL's
 * text form, as the rows endpoint returns them: json and arrays as their text
 * (`{"a": 1}`, `{1,2}`), and bytea as hex, `\x` followed by two hex digits per
 * byte (anything else for bytea is a 422).
 *
 * Optimistic checks: an update matches the primary key plus each edited
 * column's original value; a delete matches the primary key plus the original
 * value of every other column. Values are compared by their text form after a
 * round trip through the column's type, `col::text IS NOT DISTINCT FROM
 * CAST(? AS type)::text`, so types without an equality operator (json, xml,
 * point) work too, and NULLs match. Original values of masked columns are
 * never required: they are skipped, or checked when an admin unmasks and
 * sends them. When the statement matches no
 * row, nothing is written: RowConflict (409) carries the row as it is now.
 *
 * Masked columns (S14) can't be written, and come back masked, unless an
 * admin unmasks (audited). A masked primary key column makes the table
 * read-only for everyone else, since rows are addressed and audited by key.
 * Generated and GENERATED ALWAYS identity columns are never writable.
 *
 * Each operation runs in its own transaction through QueryRunner::atomic()
 * (the role's login and timeout, native prepares, session reset), writes one
 * audit entry (`row.insert`, `row.update`, `row.delete`, with the table and
 * primary key values, never other values), then fires RowChanged when the
 * row changed. `preview` builds the same SQL with literals rendered for
 * display only (PDO::quote) and runs nothing.
 *
 * @phpstan-type Target array{schema: string, table: string, qualified: string, columns: array<string, array{cast: string, writable: bool, sensitive: bool, masked: bool}>, key: list<string>, unmask: bool}
 */
final class RowWriter
{
    public const INSERT = 'insert';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public function __construct(
        private DatabaseManager $db,
        private QueryRunner $runner,
        private Masker $masker,
    ) {
        //
    }

    /**
     * Insert a row from `$values` (column => value; omitted columns get their
     * default). An empty `$values` is a 422 unless `defaults` is set, so an
     * empty form can't insert a row of defaults by accident (F25d). Returns
     * `['row' => …]`, the new row, or `['sql' => …]` for a preview.
     *
     * @param  array{unmask?: bool, preview?: bool, defaults?: bool}  $options
     * @return array{row: array<string, mixed>}|array{sql: string}
     *
     * @throws ObjectNotFoundException|ValidationException|AuthorizationException|QueryFailed
     */
    public function insert(string $connection, string $role, string $schema, string $table, mixed $values, array $options = []): array
    {
        $target = $this->target($connection, $role, $schema, $table, (bool) ($options['unmask'] ?? false));
        $values = $this->values($target, $values, 'values');

        if ($values === [] && ! (bool) ($options['defaults'] ?? false)) {
            throw self::invalid('values', 'Give at least one value, or send defaults: true to insert a row of column defaults.');
        }

        $build = function (Closure $param) use ($target, $values): string {
            if ($values === []) {
                return "INSERT INTO {$target['qualified']} DEFAULT VALUES RETURNING ".$this->returning($target);
            }

            $placeholders = [];

            foreach ($values as $column => $value) {
                $placeholders[] = $param($value, $target['columns'][$column]['cast']);
            }

            return "INSERT INTO {$target['qualified']} (".implode(', ', array_map([$this, 'quote'], array_keys($values))).')'
                .' VALUES ('.implode(', ', $placeholders).') RETURNING '.$this->returning($target);
        };

        return $this->run($connection, $role, self::INSERT, $target, null, $build, $options);
    }

    /**
     * Update the row whose primary key is `$key` with `$changes`, provided
     * each edited column still holds its value in `$original`.
     *
     * `overwrite` marks a deliberate "overwrite with mine" after a conflict:
     * `$original` is then the row the 409 returned, still checked as usual,
     * and the audit entry gets `meta.overwrite` (F25c). It changes no check.
     *
     * @param  array{unmask?: bool, preview?: bool, overwrite?: bool}  $options
     * @return array{row: array<string, mixed>}|array{sql: string}
     *
     * @throws ObjectNotFoundException|ValidationException|AuthorizationException|QueryFailed|RowConflict
     */
    public function update(string $connection, string $role, string $schema, string $table, mixed $key, mixed $original, mixed $changes, array $options = []): array
    {
        $target = $this->target($connection, $role, $schema, $table, (bool) ($options['unmask'] ?? false));
        $key = $this->key($target, $key);
        $changes = $this->values($target, $changes, 'changes');

        if ($changes === []) {
            throw self::invalid('changes', 'There are no changes to save.');
        }

        $checks = $this->originals($target, $original, array_keys($changes));

        $build = function (Closure $param) use ($target, $key, $changes, $checks): string {
            $set = [];

            foreach ($changes as $column => $value) {
                $set[] = $this->quote($column).' = '.$param($value, $target['columns'][$column]['cast']);
            }

            return "UPDATE {$target['qualified']} SET ".implode(', ', $set)
                .' WHERE '.$this->where($target, $key, $checks, $param)
                .' RETURNING '.$this->returning($target);
        };

        return $this->run($connection, $role, self::UPDATE, $target, $key, $build, $options);
    }

    /**
     * Delete the row whose primary key is `$key`, provided every other
     * unmasked column still holds its value in `$original`.
     *
     * @param  array{unmask?: bool, preview?: bool}  $options
     * @return array{row: null}|array{sql: string}
     *
     * @throws ObjectNotFoundException|ValidationException|AuthorizationException|QueryFailed|RowConflict
     */
    public function delete(string $connection, string $role, string $schema, string $table, mixed $key, mixed $original, array $options = []): array
    {
        $target = $this->target($connection, $role, $schema, $table, (bool) ($options['unmask'] ?? false));
        $key = $this->key($target, $key);
        $checks = $this->originals($target, $original, array_values(array_diff(array_keys($target['columns']), $target['key'])));

        $build = fn (Closure $param): string => "DELETE FROM {$target['qualified']} WHERE ".$this->where($target, $key, $checks, $param);

        /** @var array{row: null}|array{sql: string} */
        return $this->run($connection, $role, self::DELETE, $target, $key, $build, $options);
    }

    /**
     * Build, then preview or run the statement, audit it and fire RowChanged.
     *
     * @param  Target  $target
     * @param  array<string, string>|null  $key  the primary key of the row to change, null for an insert
     * @param  Closure(Closure(string|null, string): string): string  $build
     * @param  array{unmask?: bool, preview?: bool, defaults?: bool, overwrite?: bool}  $options
     * @return array{row: array<string, mixed>|null}|array{sql: string}
     */
    private function run(string $connection, string $role, string $operation, array $target, ?array $key, Closure $build, array $options): array
    {
        if ((bool) ($options['preview'] ?? false)) {
            $pdo = $this->db->connection($connection)->getPdo();

            return ['sql' => $build(fn (?string $value, string $cast): string => 'CAST('.($value === null ? 'NULL' : $pdo->quote($value)).' AS '.$cast.')')];
        }

        $bindings = [];
        $sql = $build(function (?string $value, string $cast) use (&$bindings): string {
            $bindings[] = $value;

            return 'CAST(? AS '.$cast.')';
        });

        $started = hrtime(true);
        $failure = null;
        $result = null;

        try {
            $result = $this->runner->atomic($connection, $role, function (Closure $statement) use ($target, $key, $sql, $bindings): array {
                $done = $statement($sql, $bindings);

                if ($done['affected'] > 1) {
                    // A full primary key can't match twice; roll back rather than trust it.
                    throw new QueryFailed('The statement matched more than one row, so it was rolled back.');
                }

                if ($done['affected'] === 1 || $key === null) {
                    return ['row' => self::row($done), 'affected' => $done['affected'], 'conflict' => false];
                }

                // Matched nothing: read the row as it is now, in the same transaction.
                $current = [];
                $read = 'SELECT '.$this->returning($target)." FROM {$target['qualified']} WHERE ".$this->where($target, $key, [], function (?string $value, string $cast) use (&$current): string {
                    $current[] = $value;

                    return 'CAST(? AS '.$cast.')';
                });

                return ['row' => self::row($statement($read, $current)), 'affected' => 0, 'conflict' => true];
            });
        } catch (QueryFailed $e) {
            $failure = $e;
        }

        $row = $result['row'] ?? null;
        $auditKey = $key ?? ($row === null ? [] : array_map(
            fn (mixed $value): ?string => $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
            array_intersect_key($row, array_flip($target['key'])),
        ));

        $audit = DbExplorer::audit([
            'action' => 'row.'.$operation,
            'role' => $role,
            'sql' => $sql,
            'classification' => $operation === self::DELETE ? Classifier::DESTRUCTIVE : Classifier::DML,
            'duration_ms' => (hrtime(true) - $started) / 1e6,
            'row_count' => $result['affected'] ?? null,
            'outcome' => $result === null ? 'error' : ($result['conflict'] ? 'conflict' : 'success'),
            'error' => $failure === null ? null : QueryRunner::auditError($failure, $bindings),
            'meta' => array_filter([
                'table' => $target['schema'].'.'.$target['table'],
                'key' => $auditKey === [] ? null : $auditKey,
                'unmasked' => $target['unmask'] ? true : null,
                'overwrite' => $operation === self::UPDATE && (bool) ($options['overwrite'] ?? false) ? true : null,
                'sqlstate' => $failure?->sqlState,
            ], fn (mixed $value): bool => $value !== null),
        ]);

        if ($failure !== null) {
            $failure->auditId = $audit->id;

            throw $failure;
        }

        /** @var array{row: array<string, mixed>|null, affected: int, conflict: bool} $result */
        $row = $row === null ? null : $this->mask($target, $row);

        if ($result['conflict']) {
            throw new RowConflict($row, $audit->id);
        }

        event(new RowChanged($audit->id, $audit->user_id, $audit->label, $role, $target['schema'], $target['table'], $operation, $auditKey));

        return ['row' => $operation === self::DELETE ? null : $row];
    }

    /**
     * Look up the table and check the caller may edit its rows.
     *
     * @return Target
     */
    private function target(string $connection, string $role, string $schema, string $table, bool $unmask): array
    {
        if (! Role::atLeast($role, Role::EDITOR)) {
            throw new AuthorizationException('Editing rows needs the editor role.');
        }

        if ($unmask && $role !== Role::ADMIN) {
            throw new AuthorizationException('Only admins can unmask columns.');
        }

        $found = (new Introspector($this->db->connection($connection)))->writeTarget($schema, $table);

        if ($found['primary_key'] === null) {
            throw self::invalid('table', 'Rows can only be edited in tables with a primary key.');
        }

        $columns = [];

        foreach ($found['columns'] as $column) {
            $sensitive = $this->masker->masksColumn($schema, $table, $column['name']);

            $columns[$column['name']] = [
                'cast' => $column['cast'],
                'writable' => $column['writable'],
                'sensitive' => $sensitive,
                'masked' => $sensitive && ! $unmask,
            ];
        }

        foreach ($found['primary_key'] as $column) {
            if ($columns[$column]['masked']) {
                throw self::invalid('table', "The primary key column [{$column}] is masked, so rows of this table can only be edited by an admin who unmasks.");
            }
        }

        return [
            'schema' => $schema,
            'table' => $table,
            'qualified' => $this->quote($schema).'.'.$this->quote($table),
            'columns' => $columns,
            'key' => $found['primary_key'],
            'unmask' => $unmask,
        ];
    }

    /**
     * Column => value input for writing: known, writable, unmasked columns only.
     *
     * @param  Target  $target
     * @return array<string, string|null>
     */
    private function values(array $target, mixed $input, string $field): array
    {
        $values = $this->columnValues($target, $input ?? [], $field);

        foreach (array_keys($values) as $column) {
            if (! $target['columns'][$column]['writable']) {
                throw self::invalid("{$field}.{$column}", 'The column is generated, so it can\'t be written.');
            }

            if ($target['columns'][$column]['masked']) {
                throw self::invalid("{$field}.{$column}", 'The column is masked, so only an admin who unmasks can write it.');
            }
        }

        return $values;
    }

    /**
     * The primary key values: every key column, none other, none NULL.
     *
     * @param  Target  $target
     * @return array<string, string>
     */
    private function key(array $target, mixed $input): array
    {
        $values = $this->columnValues($target, $input, 'key');
        $key = [];

        foreach ($target['key'] as $column) {
            if (! isset($values[$column])) {
                throw self::invalid("key.{$column}", 'Every primary key column needs a value.');
            }

            $key[$column] = $values[$column];
        }

        foreach (array_keys($values) as $column) {
            if (! in_array((string) $column, $target['key'], true)) {
                throw self::invalid("key.{$column}", 'The column is not part of the primary key.');
            }
        }

        return $key;
    }

    /**
     * The original values of the `$columns` to check. Masked columns are
     * never required: they are skipped, or, once an admin unmasks, checked
     * only when given. Every other column in `$original` must exist; its
     * value is ignored.
     *
     * @param  Target  $target
     * @param  list<string>  $columns
     * @return array<string, string|null>
     */
    private function originals(array $target, mixed $original, array $columns): array
    {
        $values = $this->columnValues($target, $original ?? [], 'original');
        $checks = [];

        foreach ($columns as $column) {
            $sensitive = $target['columns'][$column]['sensitive'];

            if ($target['columns'][$column]['masked'] || ($sensitive && ! array_key_exists($column, $values))) {
                continue;
            }

            if (! array_key_exists($column, $values)) {
                throw self::invalid("original.{$column}", 'The original value is needed to detect conflicting changes.');
            }

            $checks[$column] = $values[$column];
        }

        return $checks;
    }

    /**
     * An object of column => value with exact, known column names (S12),
     * each value in its text form for binding.
     *
     * @param  Target  $target
     * @return array<string, string|null>
     */
    private function columnValues(array $target, mixed $input, string $field): array
    {
        if (! is_array($input)) {
            throw self::invalid($field, 'This must be an object of column names and values.');
        }

        $values = [];

        foreach ($input as $column => $value) {
            $column = (string) $column;

            if (! array_key_exists($column, $target['columns'])) {
                throw self::invalid("{$field}.{$column}", 'The column does not exist.');
            }

            $values[$column] = self::value($value, $target['columns'][$column]['cast'], "{$field}.{$column}");
        }

        return $values;
    }

    /**
     * `pk = CAST(? AS type) AND … AND col::text IS NOT DISTINCT FROM CAST(? AS type)::text …`
     *
     * @param  Target  $target
     * @param  array<string, string>  $key
     * @param  array<string, string|null>  $checks
     * @param  Closure(string|null, string): string  $param
     */
    private function where(array $target, array $key, array $checks, Closure $param): string
    {
        $conditions = [];

        foreach ($key as $column => $value) {
            $conditions[] = $this->quote($column).' = '.$param($value, $target['columns'][$column]['cast']);
        }

        foreach ($checks as $column => $value) {
            $conditions[] = $this->quote($column).'::text IS NOT DISTINCT FROM '.$param($value, $target['columns'][$column]['cast']).'::text';
        }

        return implode(' AND ', $conditions);
    }

    /**
     * @param  Target  $target
     */
    private function returning(array $target): string
    {
        return implode(', ', array_map([$this, 'quote'], array_keys($target['columns'])));
    }

    private function quote(int|string $identifier): string
    {
        // PHP turns a column named "1" into an int array key.
        return DdlBuilder::quoteIdentifier((string) $identifier);
    }

    /**
     * @param  Target  $target
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mask(array $target, array $row): array
    {
        foreach ($row as $column => $value) {
            if ($value !== null && ($target['columns'][$column]['masked'] ?? false)) {
                $row[$column] = Masker::MASK;
            }
        }

        return $row;
    }

    /**
     * The first row of a statement's result, by column name, or null.
     *
     * @param  array{columns: list<string>, rows: list<list<mixed>>, affected: int}  $result
     * @return array<string, mixed>|null
     */
    private static function row(array $result): ?array
    {
        $row = $result['rows'][0] ?? null;

        return $row === null ? null : array_combine($result['columns'], $row);
    }

    /**
     * A value in the text form PostgreSQL parses into the column's type.
     */
    private static function value(mixed $value, string $cast, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Shortest round-trip form, unlike a string cast's 14 digits.
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }

        // PostgreSQL text can't hold NUL bytes or invalid UTF-8.
        if (! is_string($value) || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            throw self::invalid($field, 'The value must be a string, number, boolean or null. Send json and arrays in their text form.');
        }

        if ($cast === 'bytea' && preg_match('/\A\\\\x(?:[0-9a-fA-F]{2})*\z/', $value) !== 1) {
            throw self::invalid($field, 'A bytea value must be hex: \x followed by two hex digits per byte.');
        }

        return $value;
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
