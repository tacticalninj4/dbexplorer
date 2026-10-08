<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Drivers\Postgres;

use Illuminate\Database\ConnectionInterface;
use TacticalNinj4\DbExplorer\Exceptions\ObjectNotFoundException;
use TacticalNinj4\DbExplorer\Security\HiddenObjects;

/**
 * Reads the PostgreSQL catalog (pg_catalog only) for the object browser, PRD §7.1.
 *
 * It runs on the explorer role's own connection and shows only what that login
 * can use: schemas it has USAGE on, relations it owns or holds any privilege
 * on, functions it can EXECUTE and types it can USE. System schemas (pg_*,
 * information_schema) are never shown, and objects matching
 * `dbexplorer.hidden` are left out of every listing (S13).
 *
 * Describing a missing, hidden or inaccessible object throws the same
 * ObjectNotFoundException, so callers can't tell them apart.
 *
 * Names from the caller are only ever bound values (S12). Objects are looked
 * up by name, then every other query is bound to their OID.
 *
 * Functions are identified by their signature, `name(argtypes)` as
 * `proname(oidvectortypes(proargtypes))` renders it, e.g. `post_count(bigint)`.
 * objects() returns it, so overloads are unambiguous.
 *
 * @phpstan-type ObjectRow array{schema: string, name: string, type: string, signature: string|null, parent: array{schema: string, name: string}|null, comment: string|null}
 * @phpstan-type ColumnRow array{position: int, name: string, type: string, nullable: bool, default: string|null, identity: string|null, generated: string|null, collation: string|null, comment: string|null}
 * @phpstan-type IndexRow array{name: string, definition: string, method: string, unique: bool, primary: bool, valid: bool, constraint: string|null}
 * @phpstan-type ConstraintRow array{name: string, type: string, definition: string, columns: list<string>, references: array{schema: string, table: string, columns: list<string>}|null}
 * @phpstan-type TriggerRow array{name: string, definition: string, function: string, enabled: bool}
 */
class Introspector
{
    /**
     * pg_class.relkind => the object type objects() reports.
     */
    public const RELATION_TYPES = [
        'r' => 'table',
        'p' => 'partitioned_table',
        'v' => 'view',
        'm' => 'materialized_view',
        'S' => 'sequence',
        'f' => 'foreign_table',
    ];

    protected const CONSTRAINT_TYPES = [
        'p' => 'primary_key',
        'u' => 'unique',
        'f' => 'foreign_key',
        'c' => 'check',
        'x' => 'exclusion',
        't' => 'trigger',
    ];

    /**
     * Schemas the login can see: not a system schema, with USAGE. Needs alias n.
     * PostgreSQL reserves the pg_ prefix, so it can't hide a user schema.
     */
    protected const VISIBLE_SCHEMA = <<<'SQL'
        n.nspname NOT LIKE 'pg\_%' AND n.nspname <> 'information_schema' AND has_schema_privilege(n.oid, 'USAGE')
        SQL;

    /**
     * Relations the login owns (directly or through a role) or holds any privilege on. Needs alias c.
     */
    protected const VISIBLE_RELATION = <<<'SQL'
        (pg_has_role(c.relowner, 'USAGE') OR CASE
            WHEN c.relkind = 'S' THEN has_sequence_privilege(c.oid, 'SELECT, USAGE, UPDATE')
            ELSE has_any_column_privilege(c.oid, 'SELECT, INSERT, UPDATE, REFERENCES')
                OR has_table_privilege(c.oid, 'DELETE, TRUNCATE, TRIGGER')
        END)
        SQL;

    /**
     * Every listed object, one branch per catalog. Functions and types that an
     * extension installed (citext's dozens of functions) are left out;
     * extensions() lists the extension itself. Relations stay, since some
     * extensions ship tables people query, like PostGIS's spatial_ref_sys.
     */
    protected const OBJECTS = <<<'SQL'
        SELECT * FROM (
            SELECT n.oid AS schema_oid, n.nspname AS schema, c.relname AS name, c.relkind::text AS kind, NULL::text AS signature,
                pn.nspname AS parent_schema, pc.relname AS parent_name, obj_description(c.oid, 'pg_class') AS comment
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            LEFT JOIN pg_inherits i ON c.relispartition AND i.inhrelid = c.oid
            LEFT JOIN pg_class pc ON pc.oid = i.inhparent
            LEFT JOIN pg_namespace pn ON pn.oid = pc.relnamespace
            WHERE c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f') AND {visible_schema} AND {visible_relation}
            UNION ALL
            SELECT n.oid, n.nspname, p.proname, CASE p.prokind WHEN 'p' THEN 'procedure' ELSE 'function' END,
                p.proname || '(' || oidvectortypes(p.proargtypes) || ')', NULL, NULL, obj_description(p.oid, 'pg_proc')
            FROM pg_proc p
            JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE p.prokind IN ('f', 'p') AND {visible_schema} AND has_function_privilege(p.oid, 'EXECUTE')
                AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
            UNION ALL
            SELECT n.oid, n.nspname, t.typname, CASE t.typtype WHEN 'e' THEN 'enum' WHEN 'd' THEN 'domain' ELSE 'composite_type' END,
                NULL, NULL, NULL, obj_description(t.oid, 'pg_type')
            FROM pg_type t
            JOIN pg_namespace n ON n.oid = t.typnamespace
            LEFT JOIN pg_class tc ON tc.oid = t.typrelid
            WHERE (t.typtype IN ('e', 'd') OR (t.typtype = 'c' AND tc.relkind = 'c')) AND {visible_schema}
                AND has_type_privilege(t.oid, 'USAGE')
                AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_type'::regclass AND d.objid = t.oid AND d.deptype = 'e')
        ) o
        SQL;

    /**
     * search() fetches at most this many matches before dropping hidden ones.
     */
    protected const SEARCH_SCAN_LIMIT = 1000;

    protected HiddenObjects $hidden;

    public function __construct(protected ConnectionInterface $connection, ?HiddenObjects $hidden = null)
    {
        $this->hidden = $hidden ?? new HiddenObjects;
    }

    /**
     * Schemas the login can use, without system and hidden schemas.
     *
     * @return list<array{name: string, owner: string, comment: string|null}>
     */
    public function schemas(): array
    {
        $rows = $this->select(
            'SELECT n.nspname AS name, pg_get_userbyid(n.nspowner) AS owner, obj_description(n.oid, \'pg_namespace\') AS comment
            FROM pg_namespace n WHERE '.self::VISIBLE_SCHEMA.' ORDER BY n.nspname',
        );

        $schemas = [];

        foreach ($rows as $row) {
            if (! $this->hidden->isHidden((string) $row['name'])) {
                $schemas[] = [
                    'name' => (string) $row['name'],
                    'owner' => (string) $row['owner'],
                    'comment' => self::nullableString($row['comment']),
                ];
            }
        }

        return $schemas;
    }

    /**
     * Installed extensions. They belong to the database, not to a schema;
     * `schema` is where their objects live. Hidden when that schema is.
     *
     * @return list<array{name: string, version: string, schema: string, comment: string|null}>
     */
    public function extensions(): array
    {
        $rows = $this->select(
            'SELECT e.extname AS name, e.extversion AS version, n.nspname AS schema, obj_description(e.oid, \'pg_extension\') AS comment
            FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace ORDER BY e.extname',
        );

        $extensions = [];

        foreach ($rows as $row) {
            if (! $this->hidden->isHidden((string) $row['schema'])) {
                $extensions[] = [
                    'name' => (string) $row['name'],
                    'version' => (string) $row['version'],
                    'schema' => (string) $row['schema'],
                    'comment' => self::nullableString($row['comment']),
                ];
            }
        }

        return $extensions;
    }

    /**
     * Every visible object in a schema, sorted by name: relations (`type` is a
     * value of RELATION_TYPES), `function`, `procedure`, `enum`, `domain` and
     * `composite_type`. Partitions carry their `parent`; functions and
     * procedures their `signature`.
     *
     * @return list<ObjectRow>
     *
     * @throws ObjectNotFoundException when the schema is missing, hidden or not usable
     */
    public function objects(string $schema): array
    {
        $oid = $this->schemaOid($schema);

        return $this->objectRows(
            self::OBJECTS.' WHERE o.schema_oid = ? ORDER BY o.name, o.kind, o.signature',
            [$oid],
        );
    }

    /**
     * Visible objects whose name contains $term, case-insensitively, across
     * every visible schema. Exact matches come first, then earlier matches.
     *
     * @return list<ObjectRow>
     */
    public function search(string $term, int $limit = 50): array
    {
        if ($term === '' || $limit < 1 || ! mb_check_encoding($term, 'UTF-8') || str_contains($term, "\0")) {
            return [];
        }

        $pattern = '%'.addcslashes($term, '\\%_').'%';

        $objects = $this->objectRows(
            self::OBJECTS." WHERE o.name ILIKE ? ESCAPE '\\'
            ORDER BY lower(o.name) = lower(?) DESC, strpos(lower(o.name), lower(?)), o.name, o.schema, o.kind, o.signature
            LIMIT ".self::SEARCH_SCAN_LIMIT,
            [$pattern, $term, $term],
        );

        return array_slice($objects, 0, $limit);
    }

    /**
     * Columns of a table, view, materialized view or foreign table, in order.
     * `identity` is `always` or `by_default`; `generated` holds the expression
     * of a stored generated column, whose `default` is then null; `collation`
     * is set only when it differs from the type's.
     *
     * @return list<ColumnRow>
     *
     * @throws ObjectNotFoundException
     */
    public function columns(string $schema, string $name): array
    {
        $relation = $this->relation($schema, $name, ['r', 'p', 'v', 'm', 'f']);

        return array_map(fn (array $row): array => [
            'position' => (int) $row['position'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'nullable' => (bool) $row['nullable'],
            'default' => self::nullableString($row['default']),
            'identity' => self::nullableString($row['identity']),
            'generated' => self::nullableString($row['generated']),
            'collation' => self::nullableString($row['collation']),
            'comment' => self::nullableString($row['comment']),
        ], $this->columnRows($relation['oid']));
    }

    /**
     * Indexes of a table or materialized view. `constraint` names the primary
     * key, unique or exclusion constraint the index backs.
     *
     * @return list<IndexRow>
     *
     * @throws ObjectNotFoundException
     */
    public function indexes(string $schema, string $name): array
    {
        $relation = $this->relation($schema, $name, ['r', 'p', 'm']);

        return array_map(fn (array $row): array => [
            'name' => (string) $row['name'],
            'definition' => (string) $row['definition'],
            'method' => (string) $row['method'],
            'unique' => (bool) $row['unique'],
            'primary' => (bool) $row['primary'],
            'valid' => (bool) $row['valid'],
            'constraint' => self::nullableString($row['constraint']),
        ], $this->indexRows($relation['oid']));
    }

    /**
     * Constraints of a table: `type` is primary_key, unique, foreign_key,
     * check, exclusion or trigger. NOT NULL is reported by columns(), not here.
     * Foreign keys carry their target in `references`. When the target is
     * hidden (S13), `references` is null and the definition ends in
     * `REFERENCES <hidden>`.
     *
     * @return list<ConstraintRow>
     *
     * @throws ObjectNotFoundException
     */
    public function constraints(string $schema, string $name): array
    {
        $relation = $this->relation($schema, $name, ['r', 'p', 'f']);

        return array_map(fn (array $row): array => [
            'name' => (string) $row['name'],
            'type' => self::CONSTRAINT_TYPES[(string) $row['type']] ?? (string) $row['type'],
            'definition' => (string) $row['definition'],
            'columns' => self::jsonList($row['columns']),
            'references' => $row['ref_table'] === null ? null : [
                'schema' => (string) $row['ref_schema'],
                'table' => (string) $row['ref_table'],
                'columns' => self::jsonList($row['ref_columns']),
            ],
        ], $this->constraintRows($relation['oid']));
    }

    /**
     * User-defined triggers of a table, view or foreign table.
     *
     * @return list<TriggerRow>
     *
     * @throws ObjectNotFoundException
     */
    public function triggers(string $schema, string $name): array
    {
        $relation = $this->relation($schema, $name, ['r', 'p', 'v', 'f']);

        $rows = $this->select(
            'SELECT t.tgname AS name, pg_get_triggerdef(t.oid) AS definition, t.tgfoid::regproc::text AS function, t.tgenabled <> \'D\' AS enabled
            FROM pg_trigger t WHERE t.tgrelid = ? AND NOT t.tgisinternal ORDER BY t.tgname',
            [$relation['oid']],
        );

        return array_map(fn (array $row): array => [
            'name' => (string) $row['name'],
            'definition' => (string) $row['definition'],
            'function' => (string) $row['function'],
            'enabled' => (bool) $row['enabled'],
        ], $rows);
    }

    /**
     * The SELECT behind a view or materialized view.
     *
     * @throws ObjectNotFoundException
     */
    public function viewDefinition(string $schema, string $name): string
    {
        $relation = $this->relation($schema, $name, ['v', 'm']);

        return (string) $this->selectValue('SELECT pg_get_viewdef(?::oid, true)', [$relation['oid']]);
    }

    /**
     * A function or procedure, by its signature as objects() returns it.
     * `returns` is null for procedures; `definition` is the full CREATE
     * statement from pg_get_functiondef().
     *
     * @return array{name: string, signature: string, type: string, arguments: string, returns: string|null, language: string, volatility: string, security_definer: bool, definition: string, comment: string|null}
     *
     * @throws ObjectNotFoundException
     */
    public function functionDefinition(string $schema, string $signature): array
    {
        $this->guardName($schema);

        if ($this->hidden->isHidden($schema) || ! mb_check_encoding($signature, 'UTF-8') || str_contains($signature, "\0")) {
            throw new ObjectNotFoundException;
        }

        $rows = $this->select(
            'SELECT p.proname AS name, p.proname || \'(\' || oidvectortypes(p.proargtypes) || \')\' AS signature, p.prokind::text AS kind,
                pg_get_function_arguments(p.oid) AS arguments, CASE WHEN p.prokind <> \'p\' THEN pg_get_function_result(p.oid) END AS returns,
                l.lanname AS language, p.provolatile::text AS volatility, p.prosecdef AS security_definer,
                pg_get_functiondef(p.oid) AS definition, obj_description(p.oid, \'pg_proc\') AS comment
            FROM pg_proc p
            JOIN pg_namespace n ON n.oid = p.pronamespace
            JOIN pg_language l ON l.oid = p.prolang
            WHERE n.nspname = ? AND p.proname || \'(\' || oidvectortypes(p.proargtypes) || \')\' = ? AND p.prokind IN (\'f\', \'p\')
                AND '.self::VISIBLE_SCHEMA.' AND has_function_privilege(p.oid, \'EXECUTE\')',
            [$schema, $signature],
        );

        $row = $rows[0] ?? null;

        if ($row === null || $this->hidden->isHidden($schema, (string) $row['name'])) {
            throw new ObjectNotFoundException;
        }

        return [
            'name' => (string) $row['name'],
            'signature' => (string) $row['signature'],
            'type' => $row['kind'] === 'p' ? 'procedure' : 'function',
            'arguments' => (string) $row['arguments'],
            'returns' => self::nullableString($row['returns']),
            'language' => (string) $row['language'],
            'volatility' => ['i' => 'immutable', 's' => 'stable', 'v' => 'volatile'][(string) $row['volatility']] ?? (string) $row['volatility'],
            'security_definer' => (bool) $row['security_definer'],
            'definition' => (string) $row['definition'],
            'comment' => self::nullableString($row['comment']),
        ];
    }

    /**
     * Size and activity of a table or materialized view. A partitioned
     * table's sizes and row estimate add up its partitions. `estimated_rows`
     * comes from pg_class.reltuples and is null before the first ANALYZE.
     * Scan counts and timestamps are null when the statistics collector has
     * nothing for the relation.
     *
     * @return array{total_bytes: int, table_bytes: int, index_bytes: int, estimated_rows: int|null, seq_scans: int|null, index_scans: int|null, live_rows: int|null, dead_rows: int|null, last_vacuum: string|null, last_autovacuum: string|null, last_analyze: string|null, last_autoanalyze: string|null}
     *
     * @throws ObjectNotFoundException
     */
    public function stats(string $schema, string $name): array
    {
        $relation = $this->relation($schema, $name, ['r', 'p', 'm']);

        $row = $this->select(
            'WITH parts AS (
                SELECT c.oid, c.reltuples FROM pg_class c WHERE c.oid = ?::oid AND c.relkind <> \'p\'
                UNION ALL
                SELECT c.oid, c.reltuples FROM pg_partition_tree(?::oid) t JOIN pg_class c ON c.oid = t.relid
                WHERE t.isleaf AND (SELECT relkind FROM pg_class WHERE oid = ?::oid) = \'p\'
            )
            SELECT
                (SELECT coalesce(sum(pg_total_relation_size(oid)), 0) FROM parts)::bigint AS total_bytes,
                (SELECT coalesce(sum(pg_table_size(oid)), 0) FROM parts)::bigint AS table_bytes,
                (SELECT coalesce(sum(pg_indexes_size(oid)), 0) FROM parts)::bigint AS index_bytes,
                (SELECT CASE WHEN bool_and(reltuples >= 0) THEN sum(reltuples) END FROM parts)::bigint AS estimated_rows,
                s.seq_scan, s.idx_scan, s.n_live_tup, s.n_dead_tup,
                s.last_vacuum::text, s.last_autovacuum::text, s.last_analyze::text, s.last_autoanalyze::text
            FROM (SELECT 1) one
            LEFT JOIN pg_stat_user_tables s ON s.relid = ?::oid',
            array_fill(0, 4, $relation['oid']),
        )[0];

        return [
            'total_bytes' => (int) $row['total_bytes'],
            'table_bytes' => (int) $row['table_bytes'],
            'index_bytes' => (int) $row['index_bytes'],
            'estimated_rows' => self::nullableInt($row['estimated_rows']),
            'seq_scans' => self::nullableInt($row['seq_scan']),
            'index_scans' => self::nullableInt($row['idx_scan']),
            'live_rows' => self::nullableInt($row['n_live_tup']),
            'dead_rows' => self::nullableInt($row['n_dead_tup']),
            'last_vacuum' => self::nullableString($row['last_vacuum']),
            'last_autovacuum' => self::nullableString($row['last_autovacuum']),
            'last_analyze' => self::nullableString($row['last_analyze']),
            'last_autoanalyze' => self::nullableString($row['last_autoanalyze']),
        ];
    }

    /**
     * A generated CREATE TABLE for a table, partitioned table or partition:
     * columns with types, collations, defaults, identity and generated
     * expressions and NOT NULL, then its constraints, then CREATE INDEX for
     * indexes that back no constraint, then comments. Constraints and indexes
     * a partition inherits from its parent are left out. Ownership, grants,
     * storage options and INHERITS are not reproduced.
     *
     * @throws ObjectNotFoundException
     */
    public function tableDdl(string $schema, string $name): string
    {
        $relation = $this->relation($schema, $name, ['r', 'p']);
        $oid = $relation['oid'];

        $table = $this->select(
            'SELECT quote_ident(n.nspname) || \'.\' || quote_ident(c.relname) AS qualified, c.relpersistence::text AS persistence,
                c.relispartition AS is_partition, pg_get_expr(c.relpartbound, c.oid) AS bound,
                CASE WHEN c.relkind = \'p\' THEN pg_get_partkeydef(c.oid) END AS partition_key,
                (SELECT quote_ident(pn.nspname) || \'.\' || quote_ident(pc.relname) FROM pg_inherits i
                    JOIN pg_class pc ON pc.oid = i.inhparent JOIN pg_namespace pn ON pn.oid = pc.relnamespace
                    WHERE c.relispartition AND i.inhrelid = c.oid) AS parent,
                quote_literal(obj_description(c.oid, \'pg_class\')) AS comment
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.oid = ?',
            [$oid],
        )[0];

        $qualified = (string) $table['qualified'];
        $isPartition = (bool) $table['is_partition'];
        $elements = [];
        $comments = [];

        foreach ($this->columnRows($oid) as $column) {
            if (! $isPartition) {
                $elements[] = self::columnDdl($column);
            }

            if ($column['comment_literal'] !== null) {
                $comments[] = "COMMENT ON COLUMN {$qualified}.{$column['quoted']} IS {$column['comment_literal']};";
            }
        }

        $omitted = [];

        foreach ($this->constraintRows($oid) as $constraint) {
            if ($constraint['hidden_target']) {
                $omitted[] = "-- Constraint {$constraint['quoted']} is omitted: it references a hidden table.";
            } elseif ($constraint['local']) {
                $elements[] = "CONSTRAINT {$constraint['quoted']} {$constraint['definition']}";
            }
        }

        $persistence = $table['persistence'] === 'u' ? 'UNLOGGED ' : '';
        $body = $elements === [] ? '' : " (\n    ".implode(",\n    ", $elements)."\n)";

        $create = $isPartition
            ? "CREATE {$persistence}TABLE {$qualified} PARTITION OF {$table['parent']}{$body}\n{$table['bound']}"
            : "CREATE {$persistence}TABLE {$qualified}".($body === '' ? ' ()' : $body);

        if ($table['partition_key'] !== null) {
            $create .= "\nPARTITION BY {$table['partition_key']}";
        }

        $statements = [$create.';'];

        if ($omitted !== []) {
            $statements[] = implode("\n", $omitted);
        }

        foreach ($this->indexRows($oid) as $index) {
            if ($index['constraint'] === null && ! $index['inherited']) {
                $statements[] = $index['definition'].';';
            }
        }

        if ($table['comment'] !== null) {
            array_unshift($comments, "COMMENT ON TABLE {$qualified} IS {$table['comment']};");
        }

        if ($comments !== []) {
            $statements[] = implode("\n", $comments);
        }

        return implode("\n\n", $statements)."\n";
    }

    /**
     * @param  array<string, mixed>  $column
     */
    protected static function columnDdl(array $column): string
    {
        $ddl = "{$column['quoted']} {$column['type']}";

        if ($column['collation'] !== null) {
            $ddl .= " COLLATE {$column['collation']}";
        }

        if ($column['generated'] !== null) {
            $ddl .= " GENERATED ALWAYS AS ({$column['generated']}) STORED";
        } elseif ($column['identity'] !== null) {
            $ddl .= $column['identity'] === 'always' ? ' GENERATED ALWAYS AS IDENTITY' : ' GENERATED BY DEFAULT AS IDENTITY';
        } elseif ($column['default'] !== null) {
            $ddl .= " DEFAULT {$column['default']}";
        }

        return $column['nullable'] ? $ddl : $ddl.' NOT NULL';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function columnRows(int $oid): array
    {
        return $this->select(
            'SELECT a.attnum AS position, a.attname AS name, quote_ident(a.attname) AS quoted, format_type(a.atttypid, a.atttypmod) AS type,
                NOT a.attnotnull AS nullable,
                CASE WHEN a.attgenerated = \'\' THEN pg_get_expr(d.adbin, d.adrelid) END AS default,
                CASE a.attidentity WHEN \'a\' THEN \'always\' WHEN \'d\' THEN \'by_default\' END AS identity,
                CASE WHEN a.attgenerated <> \'\' THEN pg_get_expr(d.adbin, d.adrelid) END AS generated,
                CASE WHEN a.attcollation <> t.typcollation THEN quote_ident(cn.nspname) || \'.\' || quote_ident(co.collname) END AS collation,
                col_description(a.attrelid, a.attnum) AS comment,
                quote_literal(col_description(a.attrelid, a.attnum)) AS comment_literal
            FROM pg_attribute a
            JOIN pg_type t ON t.oid = a.atttypid
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            LEFT JOIN pg_collation co ON co.oid = a.attcollation
            LEFT JOIN pg_namespace cn ON cn.oid = co.collnamespace
            WHERE a.attrelid = ? AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum',
            [$oid],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function indexRows(int $oid): array
    {
        return array_map(function (array $row): array {
            $row['inherited'] = (bool) $row['inherited'];

            return $row;
        }, $this->select(
            'SELECT ic.relname AS name, pg_get_indexdef(i.indexrelid) AS definition, am.amname AS method,
                i.indisunique AS unique, i.indisprimary AS primary, i.indisvalid AS valid, con.conname AS constraint,
                EXISTS (SELECT 1 FROM pg_inherits inh WHERE inh.inhrelid = i.indexrelid) AS inherited
            FROM pg_index i
            JOIN pg_class ic ON ic.oid = i.indexrelid
            JOIN pg_am am ON am.oid = ic.relam
            LEFT JOIN pg_constraint con ON con.conindid = i.indexrelid AND con.conrelid = i.indrelid AND con.contype IN (\'p\', \'u\', \'x\')
            WHERE i.indrelid = ?
            ORDER BY i.indisprimary DESC, ic.relname',
            [$oid],
        ));
    }

    /**
     * Constraints except NOT NULL (contype n, stored in pg_constraint from PostgreSQL 18).
     * `local` is false for constraints a partition or child inherits. A
     * foreign key to a hidden table gets `hidden_target`, no ref_* values and
     * a definition rebuilt from its own columns, without the target's name.
     *
     * @return list<array<string, mixed>>
     */
    protected function constraintRows(int $oid): array
    {
        return array_map(function (array $row): array {
            $row['local'] = (bool) $row['local'];
            $row['hidden_target'] = $row['ref_table'] !== null && $this->hidden->isHidden((string) $row['ref_schema'], (string) $row['ref_table']);

            if ($row['hidden_target']) {
                $row['definition'] = 'FOREIGN KEY ('.implode(', ', self::jsonList($row['quoted_columns'])).') REFERENCES <hidden>';
                $row['ref_schema'] = $row['ref_table'] = $row['ref_columns'] = null;
            }

            return $row;
        }, $this->select(
            'SELECT con.conname AS name, quote_ident(con.conname) AS quoted, con.contype::text AS type, pg_get_constraintdef(con.oid) AS definition,
                (SELECT coalesce(json_agg(a.attname ORDER BY k.ord), \'[]\')::text FROM unnest(con.conkey) WITH ORDINALITY k(attnum, ord)
                    JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum) AS columns,
                (SELECT coalesce(json_agg(quote_ident(a.attname) ORDER BY k.ord), \'[]\')::text FROM unnest(con.conkey) WITH ORDINALITY k(attnum, ord)
                    JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum) AS quoted_columns,
                fn.nspname AS ref_schema, fc.relname AS ref_table,
                (SELECT coalesce(json_agg(a.attname ORDER BY k.ord), \'[]\')::text FROM unnest(con.confkey) WITH ORDINALITY k(attnum, ord)
                    JOIN pg_attribute a ON a.attrelid = con.confrelid AND a.attnum = k.attnum) AS ref_columns,
                con.conislocal AND con.conparentid = 0 AS local
            FROM pg_constraint con
            LEFT JOIN pg_class fc ON fc.oid = con.confrelid
            LEFT JOIN pg_namespace fn ON fn.oid = fc.relnamespace
            WHERE con.conrelid = ? AND con.contype IN (\'p\', \'u\', \'f\', \'c\', \'x\', \'t\')
            ORDER BY array_position(ARRAY[\'p\', \'u\', \'f\', \'c\', \'x\', \'t\'], con.contype::text), con.conname',
            [$oid],
        ));
    }

    /**
     * Run an OBJECTS query and drop hidden rows.
     *
     * @param  array<int, mixed>  $bindings
     * @return list<ObjectRow>
     */
    protected function objectRows(string $sql, array $bindings): array
    {
        $sql = strtr($sql, ['{visible_schema}' => self::VISIBLE_SCHEMA, '{visible_relation}' => self::VISIBLE_RELATION]);
        $objects = [];

        foreach ($this->select($sql, $bindings) as $row) {
            $schema = (string) $row['schema'];
            $name = (string) $row['name'];

            if ($this->hidden->isHidden($schema, $name)) {
                continue;
            }

            $parent = null;

            if ($row['parent_name'] !== null && ! $this->hidden->isHidden((string) $row['parent_schema'], (string) $row['parent_name'])) {
                $parent = ['schema' => (string) $row['parent_schema'], 'name' => (string) $row['parent_name']];
            }

            $objects[] = [
                'schema' => $schema,
                'name' => $name,
                'type' => self::RELATION_TYPES[(string) $row['kind']] ?? (string) $row['kind'],
                'signature' => self::nullableString($row['signature']),
                'parent' => $parent,
                'comment' => self::nullableString($row['comment']),
            ];
        }

        return $objects;
    }

    /**
     * The OID of a visible, unhidden schema.
     *
     * @throws ObjectNotFoundException
     */
    protected function schemaOid(string $schema): int
    {
        $this->guardName($schema);

        if ($this->hidden->isHidden($schema)) {
            throw new ObjectNotFoundException;
        }

        $oid = $this->selectValue('SELECT n.oid FROM pg_namespace n WHERE n.nspname = ? AND '.self::VISIBLE_SCHEMA, [$schema]);

        if ($oid === null) {
            throw new ObjectNotFoundException;
        }

        return (int) $oid;
    }

    /**
     * Look up a visible, unhidden relation of one of $kinds by name.
     *
     * @param  list<string>  $kinds  pg_class.relkind values
     * @return array{oid: int, kind: string}
     *
     * @throws ObjectNotFoundException
     */
    protected function relation(string $schema, string $name, array $kinds): array
    {
        $this->guardName($schema);
        $this->guardName($name);

        if ($this->hidden->isHidden($schema, $name)) {
            throw new ObjectNotFoundException;
        }

        $rows = $this->select(
            'SELECT c.oid, c.relkind::text AS kind
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = ? AND c.relname = ? AND c.relkind::text = ANY (string_to_array(?, \',\'))
                AND '.self::VISIBLE_SCHEMA.' AND '.self::VISIBLE_RELATION,
            [$schema, $name, implode(',', $kinds)],
        );

        if ($rows === []) {
            throw new ObjectNotFoundException;
        }

        return ['oid' => (int) $rows[0]['oid'], 'kind' => (string) $rows[0]['kind']];
    }

    /**
     * Reject names PostgreSQL can't hold. It would truncate a name over 63
     * bytes before comparing, and fail on NUL bytes or invalid UTF-8.
     *
     * @throws ObjectNotFoundException
     */
    protected function guardName(string $name): void
    {
        if ($name === '' || strlen($name) > 63 || str_contains($name, "\0") || ! mb_check_encoding($name, 'UTF-8')) {
            throw new ObjectNotFoundException;
        }
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function select(string $sql, array $bindings = []): array
    {
        return array_values(array_map(fn (mixed $row): array => (array) $row, $this->connection->select($sql, $bindings)));
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    protected function selectValue(string $sql, array $bindings = []): mixed
    {
        $row = $this->select($sql, $bindings)[0] ?? null;

        return $row === null ? null : reset($row);
    }

    protected static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    protected static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<string>
     */
    protected static function jsonList(mixed $json): array
    {
        $values = is_string($json) ? json_decode($json, true) : null;

        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }
}
