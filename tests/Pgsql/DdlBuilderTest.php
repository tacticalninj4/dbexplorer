<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\DdlBuilder;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Introspector;

/**
 * Build $op on pgsql_admin and run its statements one by one, outside a transaction.
 *
 * @param  array<string, mixed>  $op
 * @return list<array{sql: string, classification: string, transactional: bool, raw_expressions: bool}>
 */
function executeDdl(array $op): array
{
    $statements = DdlBuilder::forConnection(DB::connection('pgsql_admin'))->build($op);

    foreach ($statements as $statement) {
        DB::connection('pgsql_admin')->statement($statement['sql']);
    }

    return $statements;
}

function adminIntrospector(): Introspector
{
    return new Introspector(DB::connection('pgsql_admin'));
}

beforeEach(function () {
    $this->schema = $this->scratchSchema();
});

/**
 * DdlBuilder, plus a subclass that always takes the PostgreSQL < 17 path
 * (the SELECT NULL::<type> probe), so both paths run on any server.
 *
 * @return array<string, class-string<DdlBuilder>>
 */
function typeValidators(): array
{
    $legacy = new class(fn (string $type, string $field): string => $type) extends DdlBuilder
    {
        protected static function lookupType(ConnectionInterface $connection, string $type, ?bool $regtypemod = null): ?string
        {
            return parent::lookupType($connection, $type, false);
        }
    };

    return ['to_regtypemod' => DdlBuilder::class, 'probe' => get_class($legacy)];
}

it('canonicalizes valid type names', function (string $type, string $canonical) {
    foreach (typeValidators() as $path => $validator) {
        expect($validator::validateType(DB::connection('pgsql_admin'), $type))->toBe($canonical, $path);
    }
})->with([
    ['int', 'integer'],
    ['INT4', 'integer'],
    ['varchar(255)', 'character varying(255)'],
    ['character varying(10)[]', 'character varying(10)[]'],
    ['numeric(10,2)', 'numeric(10,2)'],
    ['numeric', 'numeric'],
    ['text[]', 'text[]'],
    ['int[][]', 'integer[]'],
    ['timestamp with time zone', 'timestamp with time zone'],
    ['timestamptz', 'timestamp with time zone'],
    ['timestamp(3) without time zone', 'timestamp(3) without time zone'],
    ['double precision', 'double precision'],
    ['pg_catalog.bool', 'boolean'],
    ['fixture.mood', 'fixture.mood'],
    ['fixture.mood[]', 'fixture.mood[]'],
    ['"fixture"."mood"', 'fixture.mood'],
    ['fixture.rating', 'fixture.rating'],
    ['fixture.rating[]', 'fixture.rating[]'],
    ['fixture.address', 'fixture.address'],
    ['bit varying(5)', 'bit varying(5)'],
    ['interval day to second(3)', 'interval day to second(3)'],
    ['time(2) with time zone', 'time(2) with time zone'],
    ['  jsonb  ', 'jsonb'],
]);

it('S12 rejects type strings that are not exactly one existing type', function (string $type) {
    $admin = DB::connection('pgsql_admin');
    $admin->statement("CREATE TABLE {$this->schema}.x (id int)");

    foreach (typeValidators() as $validator) {
        expect(fn () => $validator::validateType($admin, str_replace('x', "{$this->schema}.x", $type)))
            ->toThrow(ValidationException::class);
    }

    expect(adminIntrospector()->columns($this->schema, 'x'))->toHaveCount(1);
})->with([
    'statement after the type' => 'int; DROP TABLE x',
    'closing paren and comment' => 'int) ; --',
    'unbalanced brackets' => 'text[]]',
    'unknown type' => 'no_such_type',
    'unknown schema' => 'no_such_schema.mood',
    'serial pseudo-type' => 'serial',
    'alias clause' => 'int AS x',
    'subquery typmod' => 'varchar((SELECT 1))',
    'empty' => '   ',
    'NUL byte' => "int\0",
]);

it('rejects a bad type without aborting the surrounding transaction', function () {
    $admin = DB::connection('pgsql_admin');

    $admin->transaction(function () use ($admin) {
        expect(fn () => DdlBuilder::validateType($admin, 'int; DROP TABLE x'))->toThrow(ValidationException::class)
            ->and(DdlBuilder::validateType($admin, 'int'))->toBe('integer')
            ->and((array) $admin->selectOne('SELECT 1 AS one'))->toBe(['one' => 1]);
    });
});

it('round-trips a created table through the introspector', function () {
    $schema = $this->schema;

    executeDdl(['op' => 'create_table', 'schema' => $schema, 'name' => 'user', 'columns' => [
        ['name' => 'id', 'type' => 'bigint', 'identity' => 'always', 'primary' => true],
    ]]);

    executeDdl(['op' => 'create_table', 'schema' => $schema, 'name' => 'we"ird', 'comment' => "Kids' table", 'columns' => [
        ['name' => 'id', 'type' => 'int', 'identity' => 'by_default', 'primary' => true],
        ['name' => 'select', 'type' => 'varchar(255)', 'nullable' => false, 'unique' => true],
        ['name' => 'amount', 'type' => 'numeric(10,2)', 'nullable' => false, 'default' => '0', 'check' => 'amount >= 0'],
        ['name' => 'mood', 'type' => 'fixture.mood'],
        ['name' => 'tags', 'type' => 'text[]', 'default' => "'{}'"],
        ['name' => 'seen_at', 'type' => 'timestamptz', 'default' => 'now()', 'comment' => 'Last seen'],
        ['name' => 'user_id', 'type' => 'bigint', 'references' => ['schema' => $schema, 'table' => 'user', 'columns' => ['id'], 'on_delete' => 'cascade']],
    ], 'constraints' => [
        ['type' => 'unique', 'name' => 'mood_per_user', 'columns' => ['mood', 'user_id']],
    ]]);

    executeDdl(['op' => 'create_index', 'schema' => $schema, 'table' => 'we"ird', 'name' => 'recent', 'columns' => ['seen_at'], 'where' => "seen_at > '2020-01-01'"]);
    executeDdl(['op' => 'create_index', 'schema' => $schema, 'table' => 'we"ird', 'name' => 'tag_search', 'columns' => ['tags'], 'method' => 'gin']);
    executeDdl(['op' => 'create_index', 'schema' => $schema, 'table' => 'we"ird', 'name' => 'lower_select', 'expression' => 'lower("select")', 'unique' => true]);

    $columns = adminIntrospector()->columns($schema, 'we"ird');

    expect(array_map(fn (array $c): array => [$c['name'], $c['type'], $c['nullable'], $c['default'], $c['identity'], $c['comment']], $columns))->toBe([
        ['id', 'integer', false, null, 'by_default', null],
        ['select', 'character varying(255)', false, null, null, null],
        ['amount', 'numeric(10,2)', false, '0', null, null],
        ['mood', 'fixture.mood', true, null, null, null],
        ['tags', 'text[]', true, "'{}'::text[]", null, null],
        ['seen_at', 'timestamp with time zone', true, 'now()', null, 'Last seen'],
        ['user_id', 'bigint', true, null, null, null],
    ]);

    $constraints = array_map(fn (array $c): array => [$c['type'], $c['columns'], $c['references']], adminIntrospector()->constraints($schema, 'we"ird'));

    expect($constraints)->toContain(['primary_key', ['id'], null])
        ->toContain(['unique', ['select'], null])
        ->toContain(['unique', ['mood', 'user_id'], null])
        ->toContain(['check', ['amount'], null])
        ->toContain(['foreign_key', ['user_id'], ['schema' => $schema, 'table' => 'user', 'columns' => ['id']]])
        ->toHaveCount(5);

    $fk = collect(adminIntrospector()->constraints($schema, 'we"ird'))->firstWhere('type', 'foreign_key');
    expect($fk['definition'])->toContain('ON DELETE CASCADE');

    $indexes = collect(adminIntrospector()->indexes($schema, 'we"ird'))->keyBy('name');

    expect($indexes['recent']['definition'])->toContain('USING btree (seen_at) WHERE (seen_at >')
        ->and($indexes['tag_search']['method'])->toBe('gin')
        ->and($indexes['lower_select']['unique'])->toBeTrue()
        ->and($indexes['lower_select']['definition'])->toContain('(lower(("select")::text))')
        ->and($indexes['mood_per_user']['constraint'])->toBe('mood_per_user');

    $table = collect(adminIntrospector()->objects($schema))->firstWhere('name', 'we"ird');
    expect($table['comment'])->toBe("Kids' table");
});

it('adds columns from introspected column rows, so alter forms can start from the current state', function () {
    $schema = $this->schema;
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.source (a varchar(20) NOT NULL DEFAULT 'x', b fixture.mood[], c numeric(5,1))");
    DB::connection('pgsql_admin')->statement("COMMENT ON COLUMN {$schema}.source.c IS 'cee'");
    executeDdl(['op' => 'create_table', 'schema' => $schema, 'name' => 'copy', 'columns' => [['name' => 'id', 'type' => 'int']]]);

    foreach (adminIntrospector()->columns($schema, 'source') as $column) {
        executeDdl(['op' => 'add_column', 'schema' => $schema, 'table' => 'copy', 'column' => $column]);
    }

    $strip = fn (array $columns): array => array_map(fn (array $c): array => array_diff_key($c, ['position' => true]), $columns);

    expect(array_slice($strip(adminIntrospector()->columns($schema, 'copy')), 1))->toBe($strip(adminIntrospector()->columns($schema, 'source')));
});

it('runs every alter, constraint and view operation against PostgreSQL', function () {
    $s = $this->schema;
    $admin = DB::connection('pgsql_admin');
    executeDdl(['op' => 'create_table', 'schema' => $s, 'name' => 'parents', 'columns' => [['name' => 'id', 'type' => 'int', 'nullable' => false]]]);
    executeDdl(['op' => 'create_table', 'schema' => $s, 'name' => 'items', 'columns' => [
        ['name' => 'id', 'type' => 'int', 'nullable' => false],
        ['name' => 'code', 'type' => 'text'],
        ['name' => 'parent_id', 'type' => 'int'],
    ]]);
    $admin->insert("INSERT INTO {$s}.items (id, code) VALUES (1, '42')");

    $ops = [
        ['op' => 'add_constraint', 'schema' => $s, 'table' => 'parents', 'constraint' => ['type' => 'primary_key', 'name' => 'parents_pk', 'columns' => ['id']]],
        ['op' => 'add_constraint', 'schema' => $s, 'table' => 'items', 'constraint' => ['type' => 'primary_key', 'columns' => ['id']]],
        ['op' => 'add_constraint', 'schema' => $s, 'table' => 'items', 'constraint' => ['type' => 'foreign_key', 'name' => 'items_parent', 'columns' => ['parent_id'], 'references' => ['schema' => $s, 'table' => 'parents', 'on_delete' => 'set null', 'on_update' => 'restrict']]],
        ['op' => 'add_constraint', 'schema' => $s, 'table' => 'items', 'constraint' => ['type' => 'unique', 'name' => 'items_code', 'columns' => ['code']]],
        ['op' => 'alter_column_type', 'schema' => $s, 'table' => 'items', 'name' => 'code', 'type' => 'int', 'using' => 'code::integer'],
        ['op' => 'add_constraint', 'schema' => $s, 'table' => 'items', 'constraint' => ['type' => 'check', 'name' => 'code_positive', 'expression' => 'code > 0']],
        ['op' => 'set_default', 'schema' => $s, 'table' => 'items', 'name' => 'code', 'default' => '7'],
        ['op' => 'set_not_null', 'schema' => $s, 'table' => 'items', 'name' => 'code'],
        ['op' => 'rename_column', 'schema' => $s, 'table' => 'items', 'name' => 'code', 'to' => 'number'],
        ['op' => 'comment_column', 'schema' => $s, 'table' => 'items', 'name' => 'number', 'comment' => 'n\\o'],
        ['op' => 'add_column', 'schema' => $s, 'table' => 'items', 'column' => ['name' => 'seq', 'type' => 'serial', 'comment' => 'auto']],
        ['op' => 'rename_table', 'schema' => $s, 'name' => 'items', 'to' => 'things'],
        ['op' => 'comment_table', 'schema' => $s, 'name' => 'things', 'comment' => 'Things'],
        ['op' => 'create_view', 'schema' => $s, 'name' => 'numbers', 'body' => "SELECT id, number FROM {$s}.things"],
        ['op' => 'create_view', 'schema' => $s, 'name' => 'numbers', 'body' => "SELECT id, number, seq FROM {$s}.things"],
        ['op' => 'create_materialized_view', 'schema' => $s, 'name' => 'number_counts', 'body' => "SELECT number, count(*) AS n FROM {$s}.things GROUP BY number"],
        ['op' => 'create_index', 'schema' => $s, 'table' => 'number_counts', 'name' => 'number_counts_number', 'columns' => ['number'], 'unique' => true],
        ['op' => 'refresh_materialized_view', 'schema' => $s, 'name' => 'number_counts', 'concurrently' => true],
        ['op' => 'refresh_materialized_view', 'schema' => $s, 'name' => 'number_counts'],
        ['op' => 'create_materialized_view', 'schema' => $s, 'name' => 'empty_counts', 'body' => 'SELECT 1 AS one', 'with_data' => false],
    ];

    foreach ($ops as $op) {
        executeDdl($op);
    }

    $columns = collect(adminIntrospector()->columns($s, 'things'))->keyBy('name');

    expect($columns['number']['type'])->toBe('integer')
        ->and($columns['number']['nullable'])->toBeFalse()
        ->and($columns['number']['default'])->toBe('7')
        ->and($columns['number']['comment'])->toBe('n\\o')
        ->and($columns['seq']['comment'])->toBe('auto')
        ->and((array) $admin->selectOne("SELECT number FROM {$s}.things"))->toBe(['number' => 42])
        ->and(collect(adminIntrospector()->objects($s))->firstWhere('name', 'things')['comment'])->toBe('Things')
        ->and(array_column(adminIntrospector()->columns($s, 'numbers'), 'name'))->toBe(['id', 'number', 'seq'])
        ->and((array) $admin->selectOne("SELECT n FROM {$s}.number_counts"))->toBe(['n' => 1]);

    $drops = [
        ['op' => 'drop_default', 'schema' => $s, 'table' => 'things', 'name' => 'number'],
        ['op' => 'drop_not_null', 'schema' => $s, 'table' => 'things', 'name' => 'number'],
        ['op' => 'comment_column', 'schema' => $s, 'table' => 'things', 'name' => 'number', 'comment' => null],
        ['op' => 'drop_constraint', 'schema' => $s, 'table' => 'things', 'name' => 'code_positive'],
        ['op' => 'drop_index', 'schema' => $s, 'name' => 'number_counts_number'],
        ['op' => 'drop_materialized_view', 'schema' => $s, 'name' => 'number_counts'],
        ['op' => 'drop_materialized_view', 'schema' => $s, 'name' => 'empty_counts'],
        ['op' => 'drop_view', 'schema' => $s, 'name' => 'numbers'],
        ['op' => 'drop_column', 'schema' => $s, 'table' => 'things', 'name' => 'seq'],
        ['op' => 'drop_constraint', 'schema' => $s, 'table' => 'parents', 'name' => 'parents_pk', 'cascade' => true],
        ['op' => 'drop_table', 'schema' => $s, 'name' => 'things'],
    ];

    $classifications = [];

    foreach ($drops as $op) {
        $classifications[$op['op']] = executeDdl($op)[0]['classification'];
    }

    expect($classifications)->toBe([
        'drop_default' => DdlBuilder::DDL,
        'drop_not_null' => DdlBuilder::DDL,
        'comment_column' => DdlBuilder::DDL,
        'drop_constraint' => DdlBuilder::DESTRUCTIVE,
        'drop_index' => DdlBuilder::DESTRUCTIVE,
        'drop_materialized_view' => DdlBuilder::DESTRUCTIVE,
        'drop_view' => DdlBuilder::DESTRUCTIVE,
        'drop_column' => DdlBuilder::DESTRUCTIVE,
        'drop_table' => DdlBuilder::DESTRUCTIVE,
    ])
        ->and(array_column(adminIntrospector()->objects($s), 'name'))->toBe(['parents'])
        ->and(adminIntrospector()->constraints($s, 'parents'))->toBe([]);
});

it('RESTRICT drops fail on dependents and CASCADE drops remove them', function () {
    $s = $this->schema;
    executeDdl(['op' => 'create_table', 'schema' => $s, 'name' => 't', 'columns' => [['name' => 'id', 'type' => 'int']]]);
    executeDdl(['op' => 'create_view', 'schema' => $s, 'name' => 'v', 'body' => "SELECT id FROM {$s}.t"]);

    // 2BP01 dependent_objects_still_exist
    expect(sqlState(fn () => executeDdl(['op' => 'drop_table', 'schema' => $s, 'name' => 't'])))->toBe('2BP01');

    executeDdl(['op' => 'drop_table', 'schema' => $s, 'name' => 't', 'cascade' => true]);

    expect(adminIntrospector()->objects($s))->toBe([]);
});

it('runs CREATE and DROP INDEX CONCURRENTLY outside a transaction only', function () {
    $s = $this->schema;
    $admin = DB::connection('pgsql_admin');
    executeDdl(['op' => 'create_table', 'schema' => $s, 'name' => 't', 'columns' => [['name' => 'email', 'type' => 'text']]]);

    $create = DdlBuilder::forConnection($admin)->build(['op' => 'create_index', 'schema' => $s, 'table' => 't', 'name' => 't_email', 'columns' => ['email'], 'concurrently' => true])[0];
    $drop = DdlBuilder::forConnection($admin)->build(['op' => 'drop_index', 'schema' => $s, 'name' => 't_email', 'concurrently' => true])[0];

    expect($create['transactional'])->toBeFalse()
        ->and($drop['transactional'])->toBeFalse()
        // 25001 active_sql_transaction
        ->and(sqlState(fn () => $admin->transaction(fn (ConnectionInterface $c) => $c->statement($create['sql']))))->toBe('25001');

    $admin->statement($create['sql']);
    expect(array_column(adminIntrospector()->indexes($s, 't'), 'valid', 'name'))->toBe(['t_email' => true]);

    $admin->statement($drop['sql']);
    expect(adminIntrospector()->indexes($s, 't'))->toBe([]);
});
