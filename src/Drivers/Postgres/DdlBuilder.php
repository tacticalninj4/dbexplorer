<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Drivers\Postgres;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PDO;

/**
 * Turns one structured schema-editing operation into SQL statements, PRD §7.4.
 * It never executes anything: callers show the SQL, then run it.
 *
 * An operation is an array with an `op` key and a `schema` on everything, so
 * every object name is schema-qualified:
 *
 * | op | keys |
 * |---|---|
 * | create_table | schema, name, columns: list<Column>, constraints?: list<Constraint>, comment? |
 * | drop_table | schema, name, cascade? |
 * | rename_table | schema, name, to |
 * | comment_table | schema, name, comment (null removes it) |
 * | add_column | schema, table, column: Column |
 * | drop_column | schema, table, name, cascade? |
 * | rename_column | schema, table, name, to |
 * | alter_column_type | schema, table, name, type, using? |
 * | set_default / drop_default | schema, table, name, default (set only) |
 * | set_not_null / drop_not_null | schema, table, name |
 * | comment_column | schema, table, name, comment (null removes it) |
 * | create_index | schema, table, name?, columns: list<string> or expression, unique?, method? (btree), where?, concurrently? |
 * | drop_index | schema, name, cascade?, concurrently? |
 * | add_constraint | schema, table, constraint: Constraint |
 * | drop_constraint | schema, table, name, cascade? |
 * | create_view | schema, name, body (CREATE OR REPLACE) |
 * | drop_view | schema, name, cascade? |
 * | create_materialized_view | schema, name, body, with_data? (true) |
 * | refresh_materialized_view | schema, name, concurrently? |
 * | drop_materialized_view | schema, name, cascade? |
 *
 * Column: name, type, nullable? (true), default?, identity? (`always` or
 * `by_default`), primary?, unique?, check?, references?, comment?. Introspector
 * column rows fit this shape, so an alter form can start from them.
 *
 * Constraint: type (`primary_key`, `unique`, `check` or `foreign_key`, as the
 * Introspector names them), name?, columns (not for check), expression (check
 * only), references (foreign_key only).
 *
 * References: schema, table, columns?, on_delete?, on_update?, with actions
 * `no action`, `restrict`, `cascade`, `set null` or `set default`.
 *
 * Every drop states RESTRICT or CASCADE explicitly; `cascade` defaults to false.
 *
 * Security:
 * - Identifiers are always quoted by quoteIdentifier(): `"` doubled, and an
 *   empty name, a NUL byte, invalid UTF-8 or more than 63 bytes (which
 *   PostgreSQL would silently truncate) is a validation error.
 * - Type strings are an injection vector, so the builder never emits one as
 *   given: it emits what the type resolver returns. forConnection() resolves
 *   through validateType(), which returns PostgreSQL's canonical spelling.
 * - Keywords (identity, FK actions, index methods) come from allowlists, and
 *   flags must be real booleans.
 * - Defaults, check and partial-index predicates, index expressions, USING
 *   clauses and view bodies are **raw SQL by design**: schema editing is
 *   admin-only, and admins can run arbitrary SQL anyway. Statements that carry
 *   any of them have `raw_expressions` set, so the UI can warn.
 *
 * Every statement comes back as `{sql, classification, transactional,
 * raw_expressions}`. Classification is `destructive` for drops and column type
 * changes (which can rewrite or lose data), `ddl` otherwise. `transactional` is
 * false for CREATE/DROP INDEX CONCURRENTLY, which can't run in a transaction.
 *
 * Invalid input throws a ValidationException keyed by the field's dot path,
 * e.g. `columns.2.type`.
 *
 * @phpstan-type Statement array{sql: string, classification: string, transactional: bool, raw_expressions: bool}
 */
class DdlBuilder
{
    public const DDL = 'ddl';

    public const DESTRUCTIVE = 'destructive';

    /**
     * PostgreSQL's identifier limit, NAMEDATALEN - 1 bytes.
     */
    public const MAX_IDENTIFIER_BYTES = 63;

    public const IDENTITY = ['always' => 'ALWAYS', 'by_default' => 'BY DEFAULT'];

    public const FK_ACTIONS = [
        'no action' => 'NO ACTION',
        'restrict' => 'RESTRICT',
        'cascade' => 'CASCADE',
        'set null' => 'SET NULL',
        'set default' => 'SET DEFAULT',
    ];

    public const INDEX_METHODS = ['btree' => 'btree', 'hash' => 'hash', 'gin' => 'gin', 'gist' => 'gist', 'brin' => 'brin'];

    /**
     * Serial pseudo-types, which only CREATE TABLE and ADD COLUMN accept and
     * to_regtype() doesn't know. Emitted from this list, never from input.
     */
    public const SERIAL_TYPES = [
        'smallserial' => 'smallserial', 'serial2' => 'smallserial',
        'serial' => 'serial', 'serial4' => 'serial',
        'bigserial' => 'bigserial', 'serial8' => 'bigserial',
    ];

    /**
     * @var Closure(string, string): string
     */
    protected Closure $resolveType;

    /**
     * @var array<string, string>
     */
    protected array $resolved = [];

    /**
     * Set by the current build() when it emits a raw expression.
     */
    protected bool $raw = false;

    /**
     * @param  Closure(string, string): string  $resolveType  given a type string and its field path,
     *                                                        returns the canonical type or throws a ValidationException
     */
    public function __construct(Closure $resolveType)
    {
        $this->resolveType = $resolveType;
    }

    /**
     * A builder whose types are checked and canonicalized by validateType() on $connection.
     */
    public static function forConnection(ConnectionInterface $connection): self
    {
        return new self(fn (string $type, string $field): string => self::validateType($connection, $type, $field));
    }

    /**
     * PostgreSQL's canonical spelling of $type, e.g. `varchar(255)` →
     * `character varying(255)`, or a ValidationException when it isn't
     * exactly one existing type name.
     *
     * `to_regtype($1)` parses the bound string with PostgreSQL's own type-name
     * parser: anything else in it (`;`, a second clause, unbalanced brackets) is
     * a syntax error, and an unknown type is NULL. The typmod then comes from
     * `to_regtypemod($1)` on PostgreSQL 17+. Older servers lack it, so the
     * string, now proven to be a lone type name, is cast in a natively prepared
     * `SELECT NULL::<type>` (one command at most) and the result column's type
     * OID and typmod are read from the driver. Either way `format_type()`
     * renders the result, schema-qualified when the type isn't on the search_path.
     *
     * Runs inside $connection->transaction(), so a rejected type rolls back to a
     * savepoint instead of aborting an open Laravel transaction.
     *
     * @throws ValidationException
     */
    public static function validateType(ConnectionInterface $connection, string $type, string $field = 'type'): string
    {
        if (str_contains($type, "\0") || trim($type) === '' || preg_match('//u', $type) !== 1) {
            throw self::invalid($field, 'The type is not valid.');
        }

        $type = trim($type);

        try {
            $canonical = $connection->transaction(fn (): ?string => static::lookupType($connection, $type));
        } catch (QueryException $e) {
            // Class 42 (syntax error or access rule) and 22 (data exception) are
            // how the type parser rejects input; anything else is a real failure.
            if (! in_array(substr((string) $e->getCode(), 0, 2), ['42', '22'], true)) {
                throw $e;
            }

            $canonical = null;
        }

        if (! is_string($canonical) || $canonical === '') {
            throw self::invalid($field, 'The type does not exist or is not a single type name.');
        }

        return $canonical;
    }

    /**
     * The canonical spelling of $type, or null when it doesn't exist. See validateType().
     *
     * @param  bool|null  $regtypemod  whether to_regtypemod() exists; null asks the server (PostgreSQL 17+)
     */
    protected static function lookupType(ConnectionInterface $connection, string $type, ?bool $regtypemod = null): ?string
    {
        $row = (array) $connection->selectOne(
            "SELECT to_regtype(?)::oid AS oid, current_setting('server_version_num')::int >= 170000 AS regtypemod",
            [$type],
        );

        if (($row['oid'] ?? null) === null) {
            return null;
        }

        if ($regtypemod ?? (bool) $row['regtypemod']) {
            $result = (array) $connection->selectOne('SELECT format_type(to_regtype(?), to_regtypemod(?)) AS type', [$type, $type]);

            return is_string($result['type'] ?? null) ? $result['type'] : null;
        }

        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('Type validation on PostgreSQL < 17 needs an Illuminate\Database\Connection.');
        }

        // Safe to embed: to_regtype() above accepted the whole string as one type name.
        $probe = $connection->getPdo()->prepare('SELECT NULL::'.$type, [PDO::ATTR_EMULATE_PREPARES => false]);
        $probe->execute();
        $meta = $probe->getColumnMeta(0);
        $probe->closeCursor();

        return self::formatProbe($connection, (int) $row['oid'], is_array($meta) ? $meta : []);
    }

    /**
     * format_type() of the type to_regtype() found, with the typmod of the
     * probe's result column from its PDO column meta. The column reports a
     * domain as its base type, so the OID comes from to_regtype(), and a domain
     * gets no typmod, like a domain-typed table column.
     *
     * @param  array<string, mixed>  $meta
     */
    protected static function formatProbe(ConnectionInterface $connection, int $oid, array $meta): ?string
    {
        $result = (array) $connection->selectOne(
            "SELECT format_type(t.oid, CASE WHEN t.typtype = 'd' THEN NULL ELSE ?::int END) AS type FROM pg_type t WHERE t.oid = ?::oid",
            [$meta['precision'] ?? null, $oid],
        );

        return is_string($result['type'] ?? null) ? $result['type'] : null;
    }

    /**
     * Quote an identifier: always double-quoted, embedded `"` doubled.
     *
     * @throws ValidationException
     */
    public static function quoteIdentifier(string $name, string $field = 'name'): string
    {
        if ($name === '') {
            throw self::invalid($field, 'The name must not be empty.');
        }

        if (str_contains($name, "\0") || preg_match('//u', $name) !== 1) {
            throw self::invalid($field, 'The name contains invalid characters.');
        }

        if (strlen($name) > self::MAX_IDENTIFIER_BYTES) {
            throw self::invalid($field, 'The name must not be longer than '.self::MAX_IDENTIFIER_BYTES.' bytes.');
        }

        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * Quote a string literal. Uses E'' syntax when it contains a backslash, so
     * the result is safe whatever standard_conforming_strings is set to.
     *
     * @throws ValidationException
     */
    public static function quoteLiteral(string $value, string $field = 'comment'): string
    {
        if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            throw self::invalid($field, 'The text contains invalid characters.');
        }

        if (str_contains($value, '\\')) {
            return "E'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
        }

        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * The statements for one operation, in execution order.
     *
     * @param  array<string, mixed>  $op
     * @return list<Statement>
     *
     * @throws ValidationException
     */
    public function build(array $op): array
    {
        $this->raw = false;
        $kind = $this->string($op, 'op');

        return match ($kind) {
            'create_table' => $this->createTable($op),
            'drop_table' => [$this->statement('DROP TABLE '.$this->name($op).$this->behavior($op), self::DESTRUCTIVE)],
            'rename_table' => [$this->statement('ALTER TABLE '.$this->name($op).' RENAME TO '.$this->identifier($op, 'to'))],
            'comment_table' => [$this->statement('COMMENT ON TABLE '.$this->name($op).' IS '.$this->comment($op))],
            'add_column' => $this->addColumn($op),
            'drop_column' => [$this->alterTable($op, 'DROP COLUMN '.$this->identifier($op, 'name').$this->behavior($op), self::DESTRUCTIVE)],
            'rename_column' => [$this->alterTable($op, 'RENAME COLUMN '.$this->identifier($op, 'name').' TO '.$this->identifier($op, 'to'))],
            'alter_column_type' => [$this->alterTable($op, $this->alterColumn($op, 'TYPE '.$this->type($op, 'type', '', false).$this->clause(' USING ', $this->raw($op, 'using'))), self::DESTRUCTIVE)],
            'set_default' => [$this->alterTable($op, $this->alterColumn($op, 'SET DEFAULT '.$this->raw($op, 'default', '', true)))],
            'drop_default' => [$this->alterTable($op, $this->alterColumn($op, 'DROP DEFAULT'))],
            'set_not_null' => [$this->alterTable($op, $this->alterColumn($op, 'SET NOT NULL'))],
            'drop_not_null' => [$this->alterTable($op, $this->alterColumn($op, 'DROP NOT NULL'))],
            'comment_column' => [$this->statement('COMMENT ON COLUMN '.$this->name($op, 'table').'.'.$this->identifier($op, 'name').' IS '.$this->comment($op))],
            'create_index' => [$this->createIndex($op)],
            'drop_index' => [$this->dropIndex($op)],
            'add_constraint' => [$this->alterTable($op, 'ADD '.$this->constraint($this->array($op, 'constraint'), 'constraint.'))],
            'drop_constraint' => [$this->alterTable($op, 'DROP CONSTRAINT '.$this->identifier($op, 'name').$this->behavior($op), self::DESTRUCTIVE)],
            'create_view' => [$this->statement('CREATE OR REPLACE VIEW '.$this->name($op)." AS\n".$this->raw($op, 'body', '', true))],
            'drop_view' => [$this->statement('DROP VIEW '.$this->name($op).$this->behavior($op), self::DESTRUCTIVE)],
            'create_materialized_view' => [$this->statement('CREATE MATERIALIZED VIEW '.$this->name($op)." AS\n".$this->raw($op, 'body', '', true).($this->flag($op, 'with_data', true) ? '' : "\nWITH NO DATA"))],
            'refresh_materialized_view' => [$this->statement('REFRESH MATERIALIZED VIEW '.($this->flag($op, 'concurrently') ? 'CONCURRENTLY ' : '').$this->name($op))],
            'drop_materialized_view' => [$this->statement('DROP MATERIALIZED VIEW '.$this->name($op).$this->behavior($op), self::DESTRUCTIVE)],
            default => throw self::invalid('op', 'The operation is not supported.'),
        };
    }

    /**
     * A best-effort Laravel `Schema::` snippet for a create_table or add_column
     * operation, or null when the operation can't be expressed with plain
     * Blueprint column methods (check, foreign keys, table constraints,
     * table comments, uncommon types).
     *
     * @param  array<string, mixed>  $op
     *
     * @throws ValidationException
     */
    public function toMigrationSnippet(array $op): ?string
    {
        $this->build($op);

        if ($op['op'] === 'create_table') {
            if (($op['constraints'] ?? []) !== [] || ($op['comment'] ?? null) !== null) {
                return null;
            }

            $method = 'create';
            $table = $this->string($op, 'schema').'.'.$this->string($op, 'name');
            $columns = array_values($this->array($op, 'columns'));
        } elseif ($op['op'] === 'add_column') {
            $method = 'table';
            $table = $this->string($op, 'schema').'.'.$this->string($op, 'table');
            $columns = [$this->array($op, 'column')];
        } else {
            return null;
        }

        $lines = [];

        foreach ($columns as $column) {
            $line = is_array($column) ? $this->blueprintColumn($column) : null;

            if ($line === null) {
                return null;
            }

            $lines[] = $line;
        }

        return 'Schema::'.$method.'('.self::phpString($table).", function (Blueprint \$table) {\n    ".implode("\n    ", $lines)."\n});";
    }

    /**
     * @param  array<string, mixed>  $op
     * @return list<Statement>
     */
    protected function createTable(array $op): array
    {
        $table = $this->name($op);
        $columns = $this->array($op, 'columns');

        if ($columns === []) {
            throw self::invalid('columns', 'A table needs at least one column.');
        }

        $elements = [];
        $comments = [];

        foreach (array_values($columns) as $i => $column) {
            $path = "columns.{$i}.";
            $column = $this->ensureArray($column, rtrim($path, '.'));
            $elements[] = $this->column($column, $path);

            if (array_key_exists('comment', $column) && $column['comment'] !== null) {
                $comments[] = 'COMMENT ON COLUMN '.$table.'.'.$this->identifier($column, 'name', $path).' IS '.$this->comment($column, $path);
            }
        }

        foreach (array_values($this->array($op, 'constraints', '', [])) as $i => $constraint) {
            $elements[] = $this->constraint($this->ensureArray($constraint, "constraints.{$i}"), "constraints.{$i}.");
        }

        $statements = [$this->statement("CREATE TABLE {$table} (\n    ".implode(",\n    ", $elements)."\n)")];

        if (array_key_exists('comment', $op) && $op['comment'] !== null) {
            $statements[] = $this->statement("COMMENT ON TABLE {$table} IS ".$this->comment($op), self::DDL, true, false);
        }

        foreach ($comments as $comment) {
            $statements[] = $this->statement($comment, self::DDL, true, false);
        }

        return $statements;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return list<Statement>
     */
    protected function addColumn(array $op): array
    {
        $column = $this->array($op, 'column');
        $statements = [$this->alterTable($op, 'ADD COLUMN '.$this->column($column, 'column.'))];

        if (array_key_exists('comment', $column) && $column['comment'] !== null) {
            $statements[] = $this->statement(
                'COMMENT ON COLUMN '.$this->name($op, 'table').'.'.$this->identifier($column, 'name', 'column.').' IS '.$this->comment($column, 'column.'),
                self::DDL, true, false,
            );
        }

        return $statements;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return Statement
     */
    protected function createIndex(array $op): array
    {
        $concurrently = $this->flag($op, 'concurrently');
        $name = $this->optionalString($op, 'name');
        $method = $this->choice(self::INDEX_METHODS, $this->optionalString($op, 'method') ?? 'btree', 'method');
        $hasColumns = ($op['columns'] ?? null) !== null;
        $expression = $this->raw($op, 'expression');

        if ($hasColumns === ($expression !== null)) {
            throw self::invalid('columns', 'Give either columns or an expression.');
        }

        $sql = 'CREATE '.($this->flag($op, 'unique') ? 'UNIQUE ' : '').'INDEX '
            .($concurrently ? 'CONCURRENTLY ' : '')
            .($name === null ? '' : self::quoteIdentifier($name).' ')
            .'ON '.$this->name($op, 'table').' USING '.$method.' '
            .($expression === null ? '('.$this->identifiers($op, 'columns').')' : "(({$expression}))")
            .$this->clause(' WHERE ', $this->raw($op, 'where'));

        return $this->statement($sql, self::DDL, ! $concurrently);
    }

    /**
     * @param  array<string, mixed>  $op
     * @return Statement
     */
    protected function dropIndex(array $op): array
    {
        $concurrently = $this->flag($op, 'concurrently');

        if ($concurrently && $this->flag($op, 'cascade')) {
            throw self::invalid('cascade', 'DROP INDEX CONCURRENTLY does not support CASCADE.');
        }

        return $this->statement(
            'DROP INDEX '.($concurrently ? 'CONCURRENTLY ' : '').$this->name($op).$this->behavior($op),
            self::DESTRUCTIVE,
            ! $concurrently,
        );
    }

    /**
     * A column definition for CREATE TABLE or ADD COLUMN.
     *
     * @param  array<string, mixed>  $column
     */
    protected function column(array $column, string $path): string
    {
        $sql = $this->identifier($column, 'name', $path).' '.$this->type($column, 'type', $path, true);
        $identity = $this->optionalString($column, 'identity', $path);
        $default = $this->raw($column, 'default', $path);

        if ($identity !== null) {
            if ($default !== null) {
                throw self::invalid($path.'default', 'An identity column cannot have a default.');
            }

            $sql .= ' GENERATED '.$this->choice(self::IDENTITY, $identity, $path.'identity').' AS IDENTITY';
        }

        $sql .= $this->clause(' DEFAULT ', $default);

        if (! $this->flag($column, 'nullable', true, $path)) {
            $sql .= ' NOT NULL';
        }

        if ($this->flag($column, 'primary', false, $path)) {
            $sql .= ' PRIMARY KEY';
        }

        if ($this->flag($column, 'unique', false, $path)) {
            $sql .= ' UNIQUE';
        }

        $check = $this->raw($column, 'check', $path);
        $sql .= $check === null ? '' : " CHECK ({$check})";

        if (($column['references'] ?? null) !== null) {
            $sql .= ' '.$this->references($this->array($column, 'references', $path), $path.'references.');
        }

        return $sql;
    }

    /**
     * A table constraint for CREATE TABLE or ADD CONSTRAINT.
     *
     * @param  array<string, mixed>  $constraint
     */
    protected function constraint(array $constraint, string $path): string
    {
        $name = $this->optionalString($constraint, 'name', $path);
        $prefix = $name === null ? '' : 'CONSTRAINT '.self::quoteIdentifier($name, $path.'name').' ';

        return $prefix.match ($this->string($constraint, 'type', $path)) {
            'primary_key' => 'PRIMARY KEY ('.$this->identifiers($constraint, 'columns', $path).')',
            'unique' => 'UNIQUE ('.$this->identifiers($constraint, 'columns', $path).')',
            'check' => 'CHECK ('.$this->raw($constraint, 'expression', $path, true).')',
            'foreign_key' => 'FOREIGN KEY ('.$this->identifiers($constraint, 'columns', $path).') '
                .$this->references($this->array($constraint, 'references', $path), $path.'references.'),
            default => throw self::invalid($path.'type', 'The constraint type is not supported.'),
        };
    }

    /**
     * @param  array<string, mixed>  $references
     */
    protected function references(array $references, string $path): string
    {
        $sql = 'REFERENCES '.$this->name($references, 'table', $path);

        if (($references['columns'] ?? null) !== null) {
            $sql .= ' ('.$this->identifiers($references, 'columns', $path).')';
        }

        foreach (['on_delete' => 'ON DELETE', 'on_update' => 'ON UPDATE'] as $key => $clause) {
            $action = $this->optionalString($references, $key, $path);

            if ($action !== null) {
                $sql .= " {$clause} ".$this->choice(self::FK_ACTIONS, str_replace('_', ' ', strtolower($action)), $path.$key);
            }
        }

        return $sql;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return Statement
     */
    protected function alterTable(array $op, string $action, string $classification = self::DDL): array
    {
        return $this->statement('ALTER TABLE '.$this->name($op, 'table').' '.$action, $classification);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    protected function alterColumn(array $op, string $action): string
    {
        return 'ALTER COLUMN '.$this->identifier($op, 'name').' '.$action;
    }

    /**
     * @return Statement
     */
    protected function statement(string $sql, string $classification = self::DDL, bool $transactional = true, ?bool $raw = null): array
    {
        return [
            'sql' => $sql,
            'classification' => $classification,
            'transactional' => $transactional,
            'raw_expressions' => $raw ?? $this->raw,
        ];
    }

    /**
     * The schema-qualified, quoted name from $op's `schema` and $key.
     *
     * @param  array<string, mixed>  $op
     */
    protected function name(array $op, string $key = 'name', string $path = ''): string
    {
        return $this->identifier($op, 'schema', $path).'.'.$this->identifier($op, $key, $path);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    protected function identifier(array $op, string $key, string $path = ''): string
    {
        return self::quoteIdentifier($this->string($op, $key, $path), $path.$key);
    }

    /**
     * A comma-separated list of quoted identifiers from a non-empty list of names.
     *
     * @param  array<string, mixed>  $op
     */
    protected function identifiers(array $op, string $key, string $path = ''): string
    {
        $names = $this->array($op, $key, $path);

        if ($names === []) {
            throw self::invalid($path.$key, 'At least one column is required.');
        }

        $quoted = [];

        foreach (array_values($names) as $i => $name) {
            if (! is_string($name)) {
                throw self::invalid("{$path}{$key}.{$i}", 'The name must be a string.');
            }

            $quoted[] = self::quoteIdentifier($name, "{$path}{$key}.{$i}");
        }

        return implode(', ', $quoted);
    }

    /**
     * The canonical type for $op[$key], through the resolver.
     *
     * @param  array<string, mixed>  $op
     */
    protected function type(array $op, string $key, string $path, bool $allowSerial): string
    {
        $type = $this->string($op, $key, $path);
        $serial = self::SERIAL_TYPES[strtolower(trim($type))] ?? null;

        if ($serial !== null && $allowSerial) {
            return $serial;
        }

        return $this->resolved[$type] ??= ($this->resolveType)($type, $path.$key);
    }

    /**
     * @param  array<string, mixed>  $op
     */
    protected function comment(array $op, string $path = ''): string
    {
        if (! array_key_exists('comment', $op)) {
            throw self::invalid($path.'comment', 'The comment is required (null removes it).');
        }

        $comment = $this->optionalString($op, 'comment', $path);

        return $comment === null ? 'NULL' : self::quoteLiteral($comment, $path.'comment');
    }

    /**
     * A raw SQL expression from $op[$key], trimmed; null when absent unless required.
     *
     * @param  array<string, mixed>  $op
     */
    protected function raw(array $op, string $key, string $path = '', bool $required = false): ?string
    {
        $value = $op[$key] ?? null;

        if ($value === null && ! $required) {
            return null;
        }

        if (! is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
            throw self::invalid($path.$key, 'The SQL expression is required and must be a non-empty string.');
        }

        $this->raw = true;

        return trim($value);
    }

    /**
     * BEHAVIOR for drops: CASCADE when `cascade` is true, RESTRICT otherwise.
     *
     * @param  array<string, mixed>  $op
     */
    protected function behavior(array $op): string
    {
        return $this->flag($op, 'cascade') ? ' CASCADE' : ' RESTRICT';
    }

    protected function clause(string $keyword, ?string $value): string
    {
        return $value === null ? '' : $keyword.$value;
    }

    /**
     * @param  array<string, string>  $allowed
     */
    protected function choice(array $allowed, string $value, string $field): string
    {
        if (! isset($allowed[$value])) {
            throw self::invalid($field, 'The value must be one of: '.implode(', ', array_keys($allowed)).'.');
        }

        return $allowed[$value];
    }

    /**
     * @param  array<string, mixed>  $op
     */
    protected function string(array $op, string $key, string $path = ''): string
    {
        $value = $op[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw self::invalid($path.$key, 'The value is required and must be a string.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $op
     */
    protected function optionalString(array $op, string $key, string $path = ''): ?string
    {
        $value = $op[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw self::invalid($path.$key, 'The value must be a string.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $op
     */
    protected function flag(array $op, string $key, bool $default = false, string $path = ''): bool
    {
        $value = $op[$key] ?? $default;

        if (! is_bool($value)) {
            throw self::invalid($path.$key, 'The value must be true or false.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<mixed>|null  $default
     * @return array<mixed>
     */
    protected function array(array $op, string $key, string $path = '', ?array $default = null): array
    {
        $value = $op[$key] ?? $default;

        return $this->ensureArray($value, $path.$key);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ensureArray(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw self::invalid($field, 'The value is required and must be an array.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * One Blueprint line for a column, or null when it can't be expressed.
     *
     * @param  array<string, mixed>  $column
     */
    protected function blueprintColumn(array $column): ?string
    {
        if (($column['check'] ?? null) !== null || ($column['references'] ?? null) !== null) {
            return null;
        }

        $name = self::phpString($this->string($column, 'name'));
        $type = $this->type($column, 'type', '', true);
        $primary = ($column['primary'] ?? false) === true;
        $comment = is_string($column['comment'] ?? null) ? '->comment('.self::phpString($column['comment']).')' : '';

        if (($column['identity'] ?? null) !== null || isset(self::SERIAL_TYPES[$type])) {
            $increments = [
                'integer' => 'increments', 'serial' => 'increments',
                'bigint' => 'bigIncrements', 'bigserial' => 'bigIncrements',
                'smallint' => 'smallIncrements', 'smallserial' => 'smallIncrements',
            ];

            // Laravel's increments columns are always the primary key.
            return $primary && isset($increments[$type]) ? "\$table->{$increments[$type]}({$name}){$comment};" : null;
        }

        $simple = [
            'integer' => 'integer', 'bigint' => 'bigInteger', 'smallint' => 'smallInteger',
            'text' => 'text', 'boolean' => 'boolean', 'date' => 'date', 'uuid' => 'uuid',
            'json' => 'json', 'jsonb' => 'jsonb', 'bytea' => 'binary',
            'double precision' => 'double', 'real' => 'float',
        ];

        if (isset($simple[$type])) {
            $call = "{$simple[$type]}({$name})";
        } elseif (preg_match('/^character varying\((\d+)\)$/', $type, $m) === 1) {
            $call = "string({$name}, {$m[1]})";
        } elseif (preg_match('/^character\((\d+)\)$/', $type, $m) === 1) {
            $call = "char({$name}, {$m[1]})";
        } elseif (preg_match('/^numeric\((\d+),(\d+)\)$/', $type, $m) === 1) {
            $call = "decimal({$name}, {$m[1]}, {$m[2]})";
        } elseif (preg_match('/^timestamp(?:\((\d)\))? (with|without) time zone$/', $type, $m) === 1) {
            $call = ($m[2] === 'with' ? 'timestampTz' : 'timestamp')."({$name}, ".($m[1] === '' ? 'null' : $m[1]).')';
        } else {
            return null;
        }

        // A primary key column is NOT NULL whatever `nullable` says.
        $call .= ($column['nullable'] ?? true) === true && ! $primary ? '->nullable()' : '';
        $call .= is_string($column['default'] ?? null) ? '->default(DB::raw('.self::phpString(trim($column['default'])).'))' : '';
        $call .= $primary ? '->primary()' : '';
        $call .= ($column['unique'] ?? false) === true ? '->unique()' : '';

        return "\$table->{$call}{$comment};";
    }

    /**
     * A single-quoted PHP string literal, for migration snippets.
     */
    protected static function phpString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    protected static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field === '' ? 'op' : $field => $message]);
    }
}
