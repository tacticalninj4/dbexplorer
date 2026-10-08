<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Security;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Masks sensitive column values in grids, edit views and exports (PRD S14).
 *
 * Rules come from `dbexplorer.masking`. Each rule is either:
 *
 * - a glob pattern (`*` matches any run of characters, case-insensitive) on the
 *   qualified name `schema.table.column`. A pattern with N dots matches the last
 *   N + 1 parts: `password` matches that column anywhere, `users.ssn` table
 *   `users` in any schema, `billing.cards.number` one exact column. Patterns
 *   with more than two dots never match.
 * - a callable `fn (string $schema, string $table, string $column): bool`. A
 *   closure in a config file breaks `config:cache`; use a static method
 *   `[MyRules::class, 'masks']`, or set the rule at runtime from a provider.
 *
 * Result columns are resolved through their source table (`pgsql:table_oid`).
 * Columns with no table, such as expressions, are matched by name only: against
 * the column part of each pattern, and callables get an empty schema and table.
 *
 * Known gap: an aliased column (`SELECT password AS x FROM users`) keeps its
 * table but PostgreSQL doesn't report its source column, so it isn't masked.
 * Use a column-level REVOKE for real enforcement (docs/security.md).
 */
final class Masker
{
    /** What a masked value is replaced with. NULL stays NULL. */
    public const MASK = '••••••';

    /** @var list<non-empty-list<string>|Closure> */
    private array $rules = [];

    /** @var array<int, array{0: string, 1: string}|null> table oid => [schema, table], null when unknown */
    private array $tables = [];

    public function __construct(Repository $config)
    {
        foreach ((array) $config->get('dbexplorer.masking', []) as $rule) {
            if (is_string($rule)) {
                $this->rules[] = explode('.', mb_strtolower($rule));
            } elseif (is_callable($rule)) {
                $this->rules[] = Closure::fromCallable($rule);
            } else {
                throw new InvalidArgumentException('Each dbexplorer.masking rule must be a pattern string or a callable.');
            }
        }
    }

    /**
     * Whether the values of `schema.table.column` are masked, e.g. for a grid
     * or edit view of a table.
     */
    public function masksColumn(string $schema, string $table, string $column): bool
    {
        $parts = [mb_strtolower($schema), mb_strtolower($table), mb_strtolower($column)];

        foreach ($this->rules as $rule) {
            if ($rule instanceof Closure ? (bool) $rule($schema, $table, $column) : $this->matches($rule, $parts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which result columns to mask, by position.
     *
     * Source tables are looked up with one catalog query for the oids this
     * instance hasn't seen yet. `$unmask` is the admin-only, per-request
     * override: the role check and the audit entry belong to the caller.
     *
     * @param  array<int, array<string, mixed>>  $columnMeta  PDOStatement::getColumnMeta() of each column, in order
     * @return array<int, bool>
     */
    public function forColumns(ConnectionInterface $connection, array $columnMeta, bool $unmask = false): array
    {
        if ($unmask || $this->rules === []) {
            return array_fill_keys(array_keys($columnMeta), false);
        }

        $unknown = [];

        foreach ($columnMeta as $meta) {
            $oid = $this->tableOid($meta);

            if ($oid > 0 && ! array_key_exists($oid, $this->tables)) {
                $unknown[$oid] = $oid;
            }
        }

        if ($unknown !== []) {
            $this->resolveTables($connection, array_values($unknown));
        }

        $flags = [];

        foreach ($columnMeta as $position => $meta) {
            $name = is_scalar($meta['name'] ?? null) ? (string) $meta['name'] : '';
            $table = $this->tables[$this->tableOid($meta)] ?? null;

            $flags[$position] = $table !== null
                ? $this->masksColumn($table[0], $table[1], $name)
                : $this->masksName($name);
        }

        return $flags;
    }

    /**
     * Replace the masked values of one row. Flags apply by position, so rows
     * may be numeric (PDO::FETCH_NUM, required when column names repeat) or
     * associative, but must hold exactly one value per column.
     *
     * @param  array<array-key, mixed>  $row
     * @param  array<int, bool>  $maskFlags
     * @return array<array-key, mixed>
     */
    public function maskRow(array $row, array $maskFlags): array
    {
        if (count($row) !== count($maskFlags)) {
            throw new InvalidArgumentException('The row has '.count($row).' values for '.count($maskFlags).' columns. Fetch rows with PDO::FETCH_NUM when column names repeat.');
        }

        $flags = array_values($maskFlags);
        $position = 0;

        foreach ($row as $key => $value) {
            if ($value !== null && $flags[$position]) {
                $row[$key] = self::MASK;
            }

            $position++;
        }

        return $row;
    }

    /**
     * Mask rows lazily, one at a time, keeping their keys.
     *
     * @param  iterable<array-key, array<array-key, mixed>>  $rows
     * @param  array<int, bool>  $maskFlags
     * @return iterable<array-key, array<array-key, mixed>>
     */
    public function maskRows(iterable $rows, array $maskFlags): iterable
    {
        foreach ($rows as $key => $row) {
            yield $key => $this->maskRow($row, $maskFlags);
        }
    }

    /**
     * Whether a column with no source table, such as an expression, is masked.
     */
    private function masksName(string $column): bool
    {
        $name = mb_strtolower($column);

        foreach ($this->rules as $rule) {
            if ($rule instanceof Closure ? (bool) $rule('', '', $column) : $this->matches([$rule[count($rule) - 1]], [$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $pattern
     * @param  list<string>  $parts
     */
    private function matches(array $pattern, array $parts): bool
    {
        if (count($pattern) > count($parts)) {
            return false;
        }

        foreach (array_slice($parts, -count($pattern)) as $index => $part) {
            if (! Str::is($pattern[$index], $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int>  $oids
     */
    private function resolveTables(ConnectionInterface $connection, array $oids): void
    {
        $rows = $connection->select(
            'SELECT c.oid, n.nspname, c.relname FROM pg_catalog.pg_class c'
            .' JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace'
            .' WHERE c.oid = ANY (CAST(? AS oid[]))',
            ['{'.implode(',', $oids).'}'],
        );

        foreach ($oids as $oid) {
            $this->tables[$oid] = null;
        }

        foreach ($rows as $row) {
            $row = (array) $row;
            $this->tables[(int) $row['oid']] = [(string) $row['nspname'], (string) $row['relname']];
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function tableOid(array $meta): int
    {
        $oid = $meta['pgsql:table_oid'] ?? 0;

        return is_numeric($oid) ? (int) $oid : 0;
    }
}
