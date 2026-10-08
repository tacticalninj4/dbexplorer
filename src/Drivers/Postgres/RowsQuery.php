<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Drivers\Postgres;

use Illuminate\Validation\ValidationException;

/**
 * Builds the paged SELECT behind the data grid (PRD §7.2) from request
 * parameters, without touching the database.
 *
 * S12: every identifier in the SQL is a schema, relation or column name the
 * Introspector returned, quoted by quoteIdentifier(). Request parameters only
 * pick among those columns by exact match, pick an operator from OPERATORS,
 * or become bound values. Anything else is a ValidationException (422)
 * before any SQL is produced.
 *
 * Parameters (all optional):
 *
 * - `limit`: rows per page, 1 to the role's row cap. Defaults to 100.
 * - `sort` + `direction` (`asc` or `desc`): one column to order by.
 * - `filters[]`: `{column, op, value}`, ANDed. `op` is one of OPERATORS;
 *   `in` takes a list of up to 1000 values, `is_null` and `not_null` none.
 *   `like` and `ilike` compare the column's text form.
 * - `cursor` (keyset) or `offset` (offset pagination), see below.
 * - `exact`: also build a `count(*)` over the filtered rows.
 *
 * Pagination:
 *
 * - **Keyset** when the relation has a key (a primary key, or a unique index
 *   on NOT NULL columns): `WHERE (k1, k2) > (?, ?) ORDER BY k1, k2`. Sorting
 *   by another column orders by `(sort, k1, k2)`, so ties are broken by the
 *   key and every row has exactly one position. NULLs in the sort column
 *   always come last, in both directions (`NULLS LAST`), and a cursor that
 *   sits in the NULL tail continues with `sort IS NULL AND (k1, k2) > (?, ?)`.
 *   The cursor is an opaque base64url JSON object holding the last row's
 *   values of `cursor_columns`, plus the sort it was made for; it only pages
 *   forward.
 * - **Offset** otherwise (views, tables without a key): `LIMIT ? OFFSET ?`,
 *   with offsets capped at MAX_OFFSET. Plain tables and materialized views
 *   order by ctid after any sort column, so pages don't overlap while the
 *   data doesn't change.
 *
 * Both fetch `limit + 1` rows: an extra row means there is a next page.
 */
final class RowsQuery
{
    /**
     * Request operator => SQL operator.
     */
    public const OPERATORS = [
        '=' => '=',
        '!=' => '<>',
        '<' => '<',
        '<=' => '<=',
        '>' => '>',
        '>=' => '>=',
        'like' => 'LIKE',
        'ilike' => 'ILIKE',
        'is_null' => 'IS NULL',
        'not_null' => 'IS NOT NULL',
        'in' => 'IN',
    ];

    public const DEFAULT_LIMIT = 100;

    public const MAX_OFFSET = 100000;

    public const MAX_FILTERS = 25;

    public const MAX_IN_VALUES = 1000;

    public const MAX_CURSOR_LENGTH = 8192;

    /**
     * @var array<string, bool> column name => nullable
     */
    private array $columns = [];

    /**
     * @param  list<array{name: string, nullable: bool}>  $columns  the selectable columns, in order
     * @param  list<string>|null  $key  NOT NULL columns that identify a row, or null for offset pagination
     * @param  bool  $ctid  whether rows have a ctid to order offset pages by
     * @param  list<string>  $protected  columns that can't be sorted or filtered by, e.g. masked ones
     */
    public function __construct(
        private string $schema,
        private string $name,
        array $columns,
        private ?array $key = null,
        private bool $ctid = false,
        private array $protected = [],
    ) {
        foreach ($columns as $column) {
            $this->columns[$column['name']] = $column['nullable'];
        }

        if ($this->key === []) {
            $this->key = null;
        }
    }

    /**
     * The page query, its bindings, and what the caller needs to page on:
     * `pagination` (keyset or offset), `limit`, `offset`, `sort` (a column
     * or null for key order), `direction`, the
     * `cursor_columns` whose last-row values make the next cursor, and
     * `count`, the exact count query when `exact` was asked for.
     *
     * @param  array<mixed>  $params
     * @return array{0: string, 1: list<string|int>, 2: array{pagination: string, limit: int, offset: int|null, sort: string|null, direction: string, cursor_columns: list<string>, count: array{sql: string, bindings: list<string>}|null}}
     *
     * @throws ValidationException
     */
    public function build(array $params, int $maxLimit): array
    {
        $limit = $this->limit($params['limit'] ?? null, max(1, $maxLimit));
        $sort = $this->sort($params['sort'] ?? null);
        $direction = $this->direction($params['direction'] ?? null);
        [$where, $bindings] = $this->filters($params['filters'] ?? null);
        $exact = $this->exact($params['exact'] ?? null);

        $from = ' FROM '.self::quoteIdentifier($this->schema).'.'.self::quoteIdentifier($this->name);
        $select = 'SELECT '.implode(', ', array_map([self::class, 'quoteIdentifier'], array_keys($this->columns))).$from;

        $meta = [
            'pagination' => $this->key === null ? 'offset' : 'keyset',
            'limit' => $limit,
            'offset' => null,
            'sort' => $sort,
            'direction' => $direction,
            'cursor_columns' => [],
            'count' => $exact ? [
                'sql' => 'SELECT count(*) AS count'.$from.self::where($where),
                'bindings' => $bindings,
            ] : null,
        ];

        $sqlDirection = $direction === 'desc' ? ' DESC' : '';
        $order = [];

        if ($sort !== null) {
            $order[] = self::quoteIdentifier($sort).$sqlDirection.($this->columns[$sort] ? ' NULLS LAST' : '');
        }

        if ($this->key === null) {
            $offset = $this->offset($params['offset'] ?? null);
            $meta['offset'] = $offset;

            if ($this->ctid) {
                $order[] = 'ctid';
            }

            $sql = $select.self::where($where).self::orderBy($order).' LIMIT ? OFFSET ?';

            return [$sql, array_merge($bindings, [$limit + 1, $offset]), $meta];
        }

        // The sort column leads, then the key breaks ties. A key column used
        // as the sort column isn't repeated.
        $tiebreak = array_values(array_diff($this->key, [(string) $sort]));
        $cursorColumns = $sort === null || $sort === $this->key[0] ? $this->key : array_merge([$sort], $tiebreak);
        $meta['cursor_columns'] = $cursorColumns;

        foreach (array_slice($cursorColumns, $sort === null ? 0 : 1) as $column) {
            $order[] = self::quoteIdentifier($column).$sqlDirection;
        }

        $cursor = $params['cursor'] ?? null;

        if ($cursor !== null && $cursor !== '') {
            $values = $this->decodeCursor($cursor, $sort, $direction, $cursorColumns);
            $comparison = $direction === 'desc' ? '<' : '>';

            if ($sort !== null && $this->columns[$sort]) {
                $sortColumn = self::quoteIdentifier($sort);
                $keyColumns = array_slice($cursorColumns, 1);

                if ($values[0] === null) {
                    // In the NULL tail: only rows with a NULL sort value and a later key remain.
                    $where[] = "{$sortColumn} IS NULL AND ".self::row($keyColumns)." {$comparison} ".self::placeholders(count($keyColumns));
                    $values = array_slice($values, 1);
                } else {
                    // NULLs sort last, so they all come after any non-NULL value.
                    $where[] = self::row($cursorColumns)." {$comparison} ".self::placeholders(count($cursorColumns))." OR {$sortColumn} IS NULL";
                }
            } else {
                $where[] = self::row($cursorColumns)." {$comparison} ".self::placeholders(count($cursorColumns));
            }

            $bindings = array_merge($bindings, array_map('strval', $values));
        }

        $sql = $select.self::where($where).self::orderBy($order).' LIMIT ?';

        return [$sql, array_merge($bindings, [$limit + 1]), $meta];
    }

    /**
     * The cursor for the page after the row whose `cursor_columns` values
     * are $values, for the given sort.
     *
     * @param  list<string|int|float|bool|null>  $values
     */
    public static function encodeCursor(array $values, ?string $sort, string $direction): string
    {
        $json = json_encode(['v' => $values, 's' => $sort, 'd' => $direction], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * A PostgreSQL quoted identifier: wrapped in double quotes, embedded
     * double quotes doubled. Used for every identifier the builder emits.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function limit(mixed $limit, int $max): int
    {
        if ($limit === null || $limit === '') {
            return min(self::DEFAULT_LIMIT, $max);
        }

        $value = self::integer($limit);

        if ($value === null || $value < 1 || $value > $max) {
            throw self::invalid('limit', "The limit must be between 1 and {$max}.");
        }

        return $value;
    }

    private function offset(mixed $offset): int
    {
        if ($offset === null || $offset === '') {
            return 0;
        }

        $value = self::integer($offset);

        if ($value === null) {
            throw self::invalid('offset', 'The offset must be a whole number.');
        }

        if ($value > self::MAX_OFFSET) {
            throw self::invalid('offset', 'Offsets above '.self::MAX_OFFSET.' are not supported. Narrow the rows with filters.');
        }

        return $value;
    }

    private function sort(mixed $sort): ?string
    {
        if ($sort === null || $sort === '') {
            return null;
        }

        return $this->column($sort, 'sort', 'sort by');
    }

    private function direction(mixed $direction): string
    {
        if ($direction === null || $direction === '') {
            return 'asc';
        }

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw self::invalid('direction', 'The direction must be asc or desc.');
        }

        return $direction;
    }

    private function exact(mixed $exact): bool
    {
        if ($exact === null || $exact === '') {
            return false;
        }

        $value = is_string($exact) || is_bool($exact) || is_int($exact)
            ? filter_var($exact, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($value === null) {
            throw self::invalid('exact', 'The exact flag must be true or false.');
        }

        return $value;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} SQL conditions and their bindings
     */
    private function filters(mixed $filters): array
    {
        if ($filters === null || $filters === '') {
            return [[], []];
        }

        if (! is_array($filters)) {
            throw self::invalid('filters', 'The filters must be a list.');
        }

        if (count($filters) > self::MAX_FILTERS) {
            throw self::invalid('filters', 'No more than '.self::MAX_FILTERS.' filters are allowed.');
        }

        $conditions = [];
        $bindings = [];

        foreach (array_values($filters) as $i => $filter) {
            if (! is_array($filter)) {
                throw self::invalid("filters.{$i}", 'Each filter needs a column, an operator and a value.');
            }

            $column = self::quoteIdentifier($this->column($filter['column'] ?? null, "filters.{$i}.column", 'filter on'));
            $op = $filter['op'] ?? null;

            if (! is_string($op) || ! array_key_exists($op, self::OPERATORS)) {
                throw self::invalid("filters.{$i}.op", 'The filter operator is not supported.');
            }

            $sql = self::OPERATORS[$op];
            $value = $filter['value'] ?? null;

            if ($op === 'is_null' || $op === 'not_null') {
                $conditions[] = "{$column} {$sql}";

                continue;
            }

            if ($op === 'in') {
                if (! is_array($value) || $value === [] || count($value) > self::MAX_IN_VALUES) {
                    throw self::invalid("filters.{$i}.value", 'The in operator takes a list of 1 to '.self::MAX_IN_VALUES.' values.');
                }

                foreach (array_values($value) as $item) {
                    $bindings[] = self::value($item, "filters.{$i}.value");
                }

                $conditions[] = "{$column} IN ".self::placeholders(count($value));

                continue;
            }

            $bindings[] = self::value($value, "filters.{$i}.value");
            $conditions[] = $op === 'like' || $op === 'ilike' ? "{$column}::text {$sql} ?" : "{$column} {$sql} ?";
        }

        return [$conditions, $bindings];
    }

    /**
     * A column the caller may sort or filter by, matched exactly.
     */
    private function column(mixed $column, string $field, string $action): string
    {
        if (! is_string($column) || ! array_key_exists($column, $this->columns)) {
            throw self::invalid($field, 'The column does not exist.');
        }

        if (in_array($column, $this->protected, true)) {
            throw self::invalid($field, "The column is masked and can't be used to {$action}.");
        }

        return $column;
    }

    /**
     * The last row's values from a cursor made for this sort. Only the sort
     * column, when nullable, may hold NULL; key columns never do.
     *
     * @param  list<string>  $cursorColumns
     * @return list<string|int|float|bool|null>
     */
    private function decodeCursor(mixed $cursor, ?string $sort, string $direction, array $cursorColumns): array
    {
        $json = is_string($cursor) && strlen($cursor) <= self::MAX_CURSOR_LENGTH && preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor) === 1
            ? base64_decode(strtr($cursor, '-_', '+/'), true)
            : false;
        $data = is_string($json) ? json_decode($json, true, 4) : null;

        $values = is_array($data) ? ($data['v'] ?? null) : null;

        if (! is_array($data) || ! is_array($values) || ! array_key_exists('s', $data) || $data['s'] !== $sort || ($data['d'] ?? null) !== $direction
            || array_keys($values) !== array_keys($cursorColumns)) {
            throw self::invalid('cursor', 'The cursor is invalid or was made for another sort.');
        }

        $nullable = $sort !== null && $this->columns[$sort];
        $decoded = [];

        foreach ($values as $i => $value) {
            if (($value === null && ! ($i === 0 && $nullable)) || ($value !== null && ! is_scalar($value))) {
                throw self::invalid('cursor', 'The cursor is invalid or was made for another sort.');
            }

            if (is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
                throw self::invalid('cursor', 'The cursor is invalid or was made for another sort.');
            }

            $decoded[] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        return $decoded;
    }

    /**
     * A filter value as the string PostgreSQL parses into the column's type.
     */
    private static function value(mixed $value, string $field): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // PostgreSQL text can't hold NUL bytes or invalid UTF-8.
        if (! is_string($value) || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            throw self::invalid($field, 'The filter value must be text.');
        }

        return $value;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/\A[0-9]{1,9}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    private static function row(array $columns): string
    {
        return '('.implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)).')';
    }

    private static function placeholders(int $count): string
    {
        return '('.implode(', ', array_fill(0, $count, '?')).')';
    }

    /**
     * @param  list<string>  $conditions
     */
    private static function where(array $conditions): string
    {
        return $conditions === [] ? '' : ' WHERE ('.implode(') AND (', $conditions).')';
    }

    /**
     * @param  list<string>  $order
     */
    private static function orderBy(array $order): string
    {
        return $order === [] ? '' : ' ORDER BY '.implode(', ', $order);
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
