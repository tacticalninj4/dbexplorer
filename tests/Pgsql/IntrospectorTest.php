<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Introspector;
use TacticalNinj4\DbExplorer\Exceptions\ObjectNotFoundException;

function introspector(string $role = 'viewer'): Introspector
{
    return new Introspector(DB::connection("pgsql_{$role}"));
}

/**
 * The exception $callback throws, or null.
 */
function thrown(callable $callback): ?Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function pluck(array $rows, string $value, string $key = 'name'): array
{
    return array_column($rows, $value, $key);
}

it('lists the schemas the login can use, without system schemas', function () {
    $names = array_column(introspector()->schemas(), 'name');

    expect($names)->toContain('fixture', 'public')
        ->and($names)->not->toContain('pg_catalog', 'information_schema', 'pg_toast')
        ->and(collect(introspector()->schemas())->firstWhere('name', 'fixture'))
        ->toBe(['name' => 'fixture', 'owner' => 'dbx_admin', 'comment' => 'DbExplorer test fixture']);
});

it('lists every fixture object type in a schema', function () {
    $objects = introspector()->objects('fixture');

    expect(pluck($objects, 'type'))->toBe([
        'address' => 'composite_type',
        'big' => 'table',
        'counters' => 'table',
        'event_log' => 'table',
        'log_post_change' => 'function',
        'measurements' => 'partitioned_table',
        'measurements_2025' => 'table',
        'measurements_2026' => 'table',
        'mood' => 'enum',
        'post_count' => 'function',
        'post_stats' => 'materialized_view',
        'post_tags' => 'table',
        'posts' => 'table',
        'posts_id_seq' => 'sequence',
        'published_posts' => 'view',
        'rating' => 'domain',
        'secret_labels' => 'view',
        'secrets' => 'table',
        'slugify' => 'function',
        'sneaky' => 'function',
        'tags' => 'table',
        'ticket_seq' => 'sequence',
        'users' => 'table',
        'users_id_seq' => 'sequence',
    ])
        ->and(pluck($objects, 'signature'))->toMatchArray([
            'post_count' => 'post_count(bigint)',
            'slugify' => 'slugify(text)',
            'sneaky' => 'sneaky()',
            'users' => null,
        ])
        ->and(pluck($objects, 'parent'))->toMatchArray([
            'measurements_2025' => ['schema' => 'fixture', 'name' => 'measurements'],
            'measurements' => null,
        ])
        ->and(pluck($objects, 'comment'))->toMatchArray([
            'users' => 'People who write posts',
            'published_posts' => 'Posts with a publish date',
        ])
        // The citext extension's own functions and types are not listed as objects.
        ->and(array_column($objects, 'name'))->not->toContain('citext', 'citext_eq', 'regexp_match')
        ->and(array_unique(array_column($objects, 'schema')))->toBe(['fixture']);
});

it('lists extensions at database level', function () {
    expect(pluck(introspector()->extensions(), 'schema'))->toMatchArray(['citext' => 'fixture', 'plpgsql' => 'pg_catalog'])
        ->and(collect(introspector()->extensions())->firstWhere('name', 'citext')['version'])->toBeString()->not->toBe('');
});

it('lists a functions and procedures schema with overloads by signature', function () {
    $schema = $this->scratchSchema();

    DB::connection('pgsql_admin')->unprepared(<<<SQL
        CREATE FUNCTION {$schema}.area(r double precision) RETURNS double precision LANGUAGE sql IMMUTABLE AS 'SELECT pi() * r * r';
        CREATE FUNCTION {$schema}.area(w integer, h integer) RETURNS integer LANGUAGE sql IMMUTABLE AS 'SELECT w * h';
        CREATE FUNCTION {$schema}.area() RETURNS integer LANGUAGE sql AS 'SELECT 0';
        CREATE FUNCTION {$schema}.area(p fixture.address) RETURNS integer LANGUAGE sql AS 'SELECT 1';
        CREATE PROCEDURE {$schema}.reset_area(n integer) LANGUAGE sql AS 'SELECT n';
        COMMENT ON FUNCTION {$schema}.area(integer, integer) IS 'Rectangle';
        SQL);

    $introspector = introspector();
    $objects = $introspector->objects($schema);

    expect(array_column($objects, 'signature'))->toBe([
        'area()',
        'area(double precision)',
        'area(fixture.address)',
        'area(integer, integer)',
        'reset_area(integer)',
    ])
        ->and(array_column($objects, 'type'))->toBe(['function', 'function', 'function', 'function', 'procedure']);

    $rectangle = $introspector->functionDefinition($schema, 'area(integer, integer)');

    expect($rectangle)->toMatchArray([
        'name' => 'area',
        'signature' => 'area(integer, integer)',
        'type' => 'function',
        'arguments' => 'w integer, h integer',
        'returns' => 'integer',
        'language' => 'sql',
        'volatility' => 'immutable',
        'security_definer' => false,
        'comment' => 'Rectangle',
    ])
        ->and($rectangle['definition'])->toContain("CREATE OR REPLACE FUNCTION {$schema}.area(w integer, h integer)")->toContain('SELECT w * h')
        ->and($introspector->functionDefinition($schema, 'area(double precision)')['definition'])->toContain('pi()')
        ->and($introspector->functionDefinition($schema, 'area()')['volatility'])->toBe('volatile')
        ->and($introspector->functionDefinition($schema, 'area(fixture.address)')['arguments'])->toBe('p fixture.address')
        ->and($introspector->functionDefinition($schema, 'reset_area(integer)'))->toMatchArray(['type' => 'procedure', 'returns' => null])
        ->and($introspector->functionDefinition('fixture', 'slugify(text)'))->toMatchArray(['language' => 'plpgsql', 'returns' => 'text']);

    foreach (['area', 'area(int)', 'area(integer,integer)', 'AREA()', "area(integer); DROP TABLE {$schema}.x", 'area(text)'] as $signature) {
        expect(thrown(fn () => $introspector->functionDefinition($schema, $signature)))->toBeInstanceOf(ObjectNotFoundException::class);
    }
});

it('describes columns', function () {
    $columns = collect(introspector()->columns('fixture', 'users'))->keyBy('name');
    $posts = collect(introspector()->columns('fixture', 'posts'))->keyBy('name');

    expect($columns->keys()->all())->toBe(['id', 'email', 'name', 'password', 'api_token', 'mood', 'address', 'created_at'])
        ->and($columns['id'])->toBe([
            'position' => 1, 'name' => 'id', 'type' => 'bigint', 'nullable' => false, 'default' => null,
            'identity' => 'always', 'generated' => null, 'collation' => null, 'comment' => null,
        ])
        ->and($columns['email'])->toMatchArray(['type' => 'fixture.citext', 'nullable' => false, 'comment' => 'Case-insensitive login'])
        ->and($columns['api_token'])->toMatchArray(['type' => 'text', 'nullable' => true])
        ->and($columns['mood'])->toMatchArray(['type' => 'fixture.mood', 'default' => "'ok'::fixture.mood"])
        ->and($columns['address']['type'])->toBe('fixture.address')
        ->and($columns['created_at'])->toMatchArray(['type' => 'timestamp with time zone', 'default' => 'now()'])
        ->and($posts['id']['default'])->toBe("nextval('fixture.posts_id_seq'::regclass)")
        ->and($posts['tags']['type'])->toBe('text[]')
        ->and($posts['price']['type'])->toBe('numeric(12,2)')
        ->and($posts['rating']['type'])->toBe('fixture.rating')
        ->and(array_column(introspector()->columns('fixture', 'published_posts'), 'name'))->toBe(['id', 'title', 'author', 'published_at'])
        ->and(array_column(introspector()->columns('fixture', 'post_stats'), 'name'))->toBe(['user_id', 'posts', 'total_price'])
        ->and(array_column(introspector()->columns('fixture', 'measurements_2025'), 'name'))->toBe(['id', 'recorded_on', 'reading']);
});

it('describes generated columns, identity by default and collations', function () {
    $schema = $this->scratchSchema();

    DB::connection('pgsql_admin')->statement(<<<SQL
        CREATE TABLE {$schema}.shapes (
            id integer GENERATED BY DEFAULT AS IDENTITY,
            w integer NOT NULL,
            area integer GENERATED ALWAYS AS (w * w) STORED,
            code text COLLATE "C"
        )
        SQL);

    $columns = collect(introspector()->columns($schema, 'shapes'))->keyBy('name');

    expect($columns['id']['identity'])->toBe('by_default')
        ->and($columns['area'])->toMatchArray(['generated' => '(w * w)', 'default' => null])
        ->and($columns['code']['collation'])->toBe('pg_catalog."C"')
        ->and($columns['w']['collation'])->toBeNull();
});

it('describes indexes', function () {
    $indexes = collect(introspector()->indexes('fixture', 'posts'))->keyBy('name');

    expect($indexes->keys()->all())->toBe(['posts_pkey', 'posts_meta_idx', 'posts_published_at_idx', 'posts_uuid_unique'])
        ->and($indexes['posts_pkey'])->toMatchArray(['primary' => true, 'unique' => true, 'constraint' => 'posts_pkey', 'method' => 'btree'])
        ->and($indexes['posts_uuid_unique'])->toMatchArray(['primary' => false, 'unique' => true, 'constraint' => 'posts_uuid_unique'])
        ->and($indexes['posts_meta_idx'])->toMatchArray(['method' => 'gin', 'constraint' => null, 'valid' => true])
        ->and($indexes['posts_published_at_idx']['definition'])->toBe('CREATE INDEX posts_published_at_idx ON fixture.posts USING btree (published_at) WHERE (published_at IS NOT NULL)')
        ->and(collect(introspector()->indexes('fixture', 'users'))->firstWhere('name', 'users_lower_name_idx')['definition'])->toContain('(lower(name))')
        ->and(array_column(introspector()->indexes('fixture', 'measurements'), 'name'))->toBe(['measurements_pkey']);
});

it('describes constraints with foreign key targets', function () {
    $constraints = collect(introspector()->constraints('fixture', 'posts'))->keyBy('name');
    $postTags = collect(introspector()->constraints('fixture', 'post_tags'))->keyBy('name');

    expect($constraints->keys()->all())->toBe(['posts_pkey', 'posts_uuid_unique', 'posts_user_id_fkey', 'posts_price_check'])
        ->and($constraints['posts_pkey'])->toBe([
            'name' => 'posts_pkey', 'type' => 'primary_key', 'definition' => 'PRIMARY KEY (id)', 'columns' => ['id'], 'references' => null,
        ])
        ->and($constraints['posts_uuid_unique'])->toMatchArray(['type' => 'unique', 'columns' => ['uuid']])
        ->and($constraints['posts_price_check'])->toMatchArray(['type' => 'check', 'columns' => ['price']])
        ->and($constraints['posts_price_check']['definition'])->toContain('price >=')
        ->and($constraints['posts_user_id_fkey'])->toMatchArray([
            'type' => 'foreign_key',
            'definition' => 'FOREIGN KEY (user_id) REFERENCES fixture.users(id) ON DELETE CASCADE',
            'columns' => ['user_id'],
            'references' => ['schema' => 'fixture', 'table' => 'users', 'columns' => ['id']],
        ])
        ->and($postTags['post_tags_pkey']['columns'])->toBe(['post_id', 'tag_id'])
        ->and($postTags['post_tags_tag_id_fkey']['references'])->toBe(['schema' => 'fixture', 'table' => 'tags', 'columns' => ['id']])
        // NOT NULL is a column property, even on PostgreSQL 18 where it's a pg_constraint row.
        ->and(array_column(introspector()->constraints('fixture', 'event_log'), 'type'))->toBe([])
        ->and(array_column(introspector()->constraints('fixture', 'measurements'), 'columns', 'name'))->toBe(['measurements_pkey' => ['id', 'recorded_on']]);
});

it('describes triggers', function () {
    expect(introspector()->triggers('fixture', 'posts'))->toBe([[
        'name' => 'posts_log_change',
        'definition' => 'CREATE TRIGGER posts_log_change AFTER INSERT OR DELETE OR UPDATE ON fixture.posts FOR EACH ROW EXECUTE FUNCTION fixture.log_post_change()',
        'function' => 'fixture.log_post_change',
        'enabled' => true,
    ]])
        // FK enforcement uses internal triggers, which are not listed.
        ->and(introspector()->triggers('fixture', 'users'))->toBe([]);
});

it('returns view and materialized view definitions', function () {
    expect(introspector()->viewDefinition('fixture', 'published_posts'))->toContain('FROM fixture.posts p')->toContain('published_at IS NOT NULL')
        ->and(introspector()->viewDefinition('fixture', 'post_stats'))->toContain('GROUP BY user_id');
});

it('describes only the kinds of relation each method supports', function (string $method, string $name) {
    expect(thrown(fn () => introspector()->{$method}('fixture', $name)))->toBeInstanceOf(ObjectNotFoundException::class);
})->with([
    ['viewDefinition', 'users'],
    ['tableDdl', 'published_posts'],
    ['indexes', 'published_posts'],
    ['stats', 'ticket_seq'],
    ['columns', 'ticket_seq'],
    ['columns', 'mood'],
    ['columns', 'post_count'],
]);

it('reports table stats', function () {
    $big = introspector()->stats('fixture', 'big');
    $measurements = introspector()->stats('fixture', 'measurements');

    expect($big['estimated_rows'])->toBeGreaterThan(900000)
        ->and($big['total_bytes'])->toBe($big['table_bytes'] + $big['index_bytes'])
        ->and($big['index_bytes'])->toBeGreaterThan(0)
        ->and($big['last_analyze'])->toBeString()
        ->and($big['seq_scans'])->toBeInt()
        // A partitioned table adds up its partitions.
        ->and($measurements['estimated_rows'])->toBe(730)
        ->and($measurements['total_bytes'])->toBe(
            introspector()->stats('fixture', 'measurements_2025')['total_bytes'] + introspector()->stats('fixture', 'measurements_2026')['total_bytes'],
        )
        ->and(introspector()->stats('fixture', 'post_stats')['total_bytes'])->toBeGreaterThan(0);
});

it('generates table DDL', function () {
    $ddl = introspector()->tableDdl('fixture', 'posts');

    expect($ddl)->toStartWith("CREATE TABLE fixture.posts (\n    id bigint DEFAULT nextval('fixture.posts_id_seq'::regclass) NOT NULL,")
        ->toContain('    CONSTRAINT posts_user_id_fkey FOREIGN KEY (user_id) REFERENCES fixture.users(id) ON DELETE CASCADE')
        ->toContain('CREATE INDEX posts_meta_idx ON fixture.posts USING gin (meta);')
        ->toContain("COMMENT ON TABLE fixture.posts IS 'Blog posts, one row per post';")
        ->toContain("COMMENT ON COLUMN fixture.posts.body IS 'Long text, TOASTed for the first post';")
        // Constraint indexes come from the constraints.
        ->not->toContain('CREATE UNIQUE INDEX')
        ->and(introspector()->tableDdl('fixture', 'users'))->toContain('id bigint GENERATED ALWAYS AS IDENTITY NOT NULL')
        ->and(introspector()->tableDdl('fixture', 'measurements'))->toContain(")\nPARTITION BY RANGE (recorded_on);")
        ->and(introspector()->tableDdl('fixture', 'measurements_2025'))
        ->toBe("CREATE TABLE fixture.measurements_2025 PARTITION OF fixture.measurements\nFOR VALUES FROM ('2025-01-01') TO ('2026-01-01');\n");
});

it('generates table DDL that recreates the table', function (string $source, ?string $setup) {
    $schema = $this->scratchSchema();
    $super = DB::connection('pgsql_super');

    // Index and constraint names are per schema, so the source lives in a second one.
    if ($setup !== null) {
        $super->unprepared("CREATE SCHEMA {$schema}_src;".str_replace('{s}', "{$schema}_src", $setup));
        $source = "{$schema}_src.{$source}";
        $this->beforeApplicationDestroyed(fn () => DB::connection('pgsql_super')->statement("DROP SCHEMA IF EXISTS {$schema}_src CASCADE"));
    }

    [$fromSchema, $fromTable] = explode('.', $source);
    $ddl = introspector('super')->tableDdl($fromSchema, $fromTable);
    $copy = preg_replace('/'.preg_quote($source, '/').'\b(?!")/', "{$schema}.copy", $ddl);

    $super->unprepared($copy);

    $describe = function (string $schemaName, string $table) use ($source, $schema, $super): array {
        $introspector = introspector('super');
        $rename = fn (string $sql): string => preg_replace('/'.preg_quote($source, '/').'\b(?!")|'.preg_quote("{$schema}.copy", '/').'\b/', 'T', $sql);

        return [
            'columns' => $introspector->columns($schemaName, $table),
            'constraints' => array_map(fn (array $c): array => ['definition' => $rename($c['definition'])] + $c, $introspector->constraints($schemaName, $table)),
            'indexes' => array_map(fn (array $i): string => $rename($i['definition']), array_filter($introspector->indexes($schemaName, $table), fn (array $i): bool => $i['constraint'] === null)),
            'comment' => $super->selectOne('SELECT obj_description(c.oid, ?) AS c FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ?', ['pg_class', $schemaName, $table])->c,
            'persistence' => $super->selectOne('SELECT c.relpersistence AS p FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ?', [$schemaName, $table])->p,
        ];
    };

    $original = $describe($fromSchema, $fromTable);
    $recreated = $describe($schema, 'copy');

    // Constraint and index names are kept, so a copy in another schema matches them exactly.
    expect($recreated)->toEqual($original)
        ->and(introspector('super')->tableDdl($schema, 'copy'))->toBe(preg_replace('/'.preg_quote($source, '/').'\b(?!")/', "{$schema}.copy", $ddl));
})->with([
    'posts' => ['fixture.posts', null],
    'users' => ['fixture.users', null],
    'post_tags' => ['fixture.post_tags', null],
    'partitioned' => ['fixture.measurements', null],
    'odd names' => ['src', <<<'SQL'
        CREATE UNLOGGED TABLE {s}.src (
            "Weird ""Col""" integer GENERATED BY DEFAULT AS IDENTITY,
            "select" text COLLATE "C" DEFAULT 'it''s' NOT NULL,
            w integer NOT NULL,
            area integer GENERATED ALWAYS AS (w * w) STORED,
            CONSTRAINT "Src PK" PRIMARY KEY ("Weird ""Col"""),
            CONSTRAINT w_positive CHECK (w > 0),
            EXCLUDE USING btree (w WITH =)
        );
        CREATE INDEX "src lower" ON {s}.src (lower("select")) WHERE w > 1;
        COMMENT ON TABLE {s}.src IS 'Quote '' and backslash \ inside';
        COMMENT ON COLUMN {s}.src."Weird ""Col""" IS 'Weird';
        SQL],
]);

it('S13 leaves hidden objects out of listings and search', function () {
    config(['dbexplorer.hidden' => ['fixture.secrets', 'fixture.slug*', 'fixture.mood']]);

    $names = array_column(introspector()->objects('fixture'), 'name');
    $search = array_column(introspector()->search('se'), 'name');

    expect($names)->not->toContain('secrets', 'slugify', 'mood')
        ->and($names)->toContain('secret_labels', 'users', 'post_count')
        ->and($search)->toContain('secret_labels', 'users')
        ->and($search)->not->toContain('secrets')
        ->and(introspector()->search('slugify'))->toBe([])
        ->and(introspector()->search('secrets'))->toBe([]);
});

it('S13 hides whole schemas', function (string $pattern) {
    config(['dbexplorer.hidden' => [$pattern]]);

    expect(array_column(introspector()->schemas(), 'name'))->not->toContain('fixture')
        ->and(array_column(introspector()->extensions(), 'name'))->not->toContain('citext')
        ->and(array_column(introspector()->search('post'), 'schema'))->not->toContain('fixture')
        ->and(thrown(fn () => introspector()->objects('fixture')))->toBeInstanceOf(ObjectNotFoundException::class)
        ->and(thrown(fn () => introspector()->columns('fixture', 'users')))->toBeInstanceOf(ObjectNotFoundException::class)
        ->and(thrown(fn () => introspector()->functionDefinition('fixture', 'slugify(text)')))->toBeInstanceOf(ObjectNotFoundException::class);
})->with(['fixture', 'fix*', 'fixture.*']);

it('S13 hides DbExplorer tables by default', function () {
    $public = array_column(introspector('super')->objects('public'), 'name');

    expect(DB::connection('pgsql_super')->select("SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = 'dbx_audit_log'"))->not->toBe([])
        ->and(array_filter($public, fn (string $name): bool => str_starts_with($name, 'dbx_')))->toBe([])
        ->and(introspector('super')->search('dbx_'))->toBe([])
        ->and(thrown(fn () => introspector('super')->columns('public', 'dbx_audit_log')))->toBeInstanceOf(ObjectNotFoundException::class);
});

it('S13 answers a hidden object exactly like a missing one', function (string $method, string $hidden, string $missing) {
    config(['dbexplorer.hidden' => ['fixture.secrets', 'fixture.slugify', 'fixture.secret_labels']]);

    $hiddenError = thrown(fn () => introspector()->{$method}('fixture', $hidden));
    $missingError = thrown(fn () => introspector()->{$method}('fixture', $missing));

    expect($hiddenError)->toBeInstanceOf(ObjectNotFoundException::class)
        ->and($missingError)->toBeInstanceOf(ObjectNotFoundException::class)
        ->and($hiddenError?->getMessage())->toBe($missingError?->getMessage())
        ->and($hiddenError?->getCode())->toBe($missingError?->getCode());
})->with([
    ['columns', 'secrets', 'nope'],
    ['indexes', 'secrets', 'nope'],
    ['constraints', 'secrets', 'nope'],
    ['triggers', 'secrets', 'nope'],
    ['stats', 'secrets', 'nope'],
    ['tableDdl', 'secrets', 'nope'],
    ['viewDefinition', 'secret_labels', 'nope'],
    ['functionDefinition', 'slugify(text)', 'nope(text)'],
]);

it('shows the viewer only what its login can use', function () {
    $schema = $this->scratchSchema();
    $private = "{$schema}_private";
    $super = DB::connection('pgsql_super');

    $super->unprepared(<<<SQL
        CREATE SCHEMA {$private} AUTHORIZATION dbx_admin;
        CREATE TABLE {$private}.notes (id int);
        SET ROLE dbx_admin;
        CREATE TABLE {$schema}.open (id int);
        CREATE TABLE {$schema}.closed (id int);
        REVOKE ALL ON {$schema}.closed FROM dbx_viewer;
        CREATE TABLE {$schema}.partly (id int, secret text);
        REVOKE ALL ON {$schema}.partly FROM dbx_viewer;
        GRANT SELECT (id) ON {$schema}.partly TO dbx_viewer;
        CREATE SEQUENCE {$schema}.closed_seq;
        CREATE FUNCTION {$schema}.open_fn() RETURNS int LANGUAGE sql AS 'SELECT 1';
        CREATE FUNCTION {$schema}.closed_fn() RETURNS int LANGUAGE sql AS 'SELECT 1';
        REVOKE EXECUTE ON FUNCTION {$schema}.closed_fn() FROM PUBLIC;
        CREATE TYPE {$schema}.closed_type AS ENUM ('a');
        REVOKE USAGE ON TYPE {$schema}.closed_type FROM PUBLIC;
        RESET ROLE;
        SQL);

    try {
        $viewer = introspector('viewer');
        $admin = introspector('admin');

        expect(array_column($viewer->objects($schema), 'name'))->toBe(['open', 'open_fn', 'partly'])
            ->and(array_column($admin->objects($schema), 'name'))->toBe(['closed', 'closed_fn', 'closed_seq', 'closed_type', 'open', 'open_fn', 'partly'])
            // The editor got table and sequence privileges by default, but not EXECUTE on closed_fn or USAGE on closed_type.
            ->and(array_column(introspector('editor')->objects($schema), 'name'))->toBe(['closed', 'closed_seq', 'open', 'open_fn', 'partly'])
            ->and(array_column($viewer->search('closed'), 'name'))->toBe([])
            ->and(array_column($viewer->columns($schema, 'partly'), 'name'))->toBe(['id', 'secret'])
            ->and(thrown(fn () => $viewer->columns($schema, 'closed')))->toBeInstanceOf(ObjectNotFoundException::class)
            ->and(thrown(fn () => $viewer->functionDefinition($schema, 'closed_fn()')))->toBeInstanceOf(ObjectNotFoundException::class)
            ->and($admin->columns($schema, 'closed'))->toHaveCount(1)
            ->and($admin->functionDefinition($schema, 'closed_fn()')['name'])->toBe('closed_fn')
            // No USAGE on the schema: nothing in it shows, even though the catalog lists it.
            ->and(array_column($viewer->schemas(), 'name'))->not->toContain($private)
            ->and(array_column($admin->schemas(), 'name'))->toContain($private)
            ->and(thrown(fn () => $viewer->objects($private)))->toBeInstanceOf(ObjectNotFoundException::class)
            ->and(thrown(fn () => $viewer->columns($private, 'notes')))->toBeInstanceOf(ObjectNotFoundException::class)
            ->and(array_column($viewer->search('notes'), 'name'))->toBe([])
            // The admin owns the schema but has no privilege on the superuser's table in it.
            ->and(array_column($admin->search('notes'), 'schema'))->toBe([])
            ->and(array_column(introspector('super')->search('notes'), 'schema'))->toBe([$private]);
    } finally {
        DB::disconnect('pgsql_viewer');
        DB::disconnect('pgsql_admin');
        DB::disconnect('pgsql_editor');
        $super->statement("DROP SCHEMA {$private} CASCADE");
    }
});

it('S12 treats names as literal values, never as SQL', function (string $name) {
    $introspector = introspector('admin');

    foreach (['columns', 'indexes', 'constraints', 'triggers', 'stats', 'tableDdl', 'viewDefinition'] as $method) {
        expect(thrown(fn () => $introspector->{$method}('fixture', $name)))->toBeInstanceOf(ObjectNotFoundException::class)
            ->and(thrown(fn () => $introspector->{$method}($name, 'users')))->toBeInstanceOf(ObjectNotFoundException::class);
    }

    expect(thrown(fn () => $introspector->objects($name)))->toBeInstanceOf(ObjectNotFoundException::class)
        ->and(thrown(fn () => $introspector->functionDefinition('fixture', $name)))->toBeInstanceOf(ObjectNotFoundException::class)
        ->and(thrown(fn () => $introspector->functionDefinition($name, 'slugify(text)')))->toBeInstanceOf(ObjectNotFoundException::class)
        ->and($introspector->search($name))->toBeArray()
        ->and((int) DB::connection('pgsql_super')->selectOne('SELECT count(*) AS n FROM fixture.tags')->n)->toBe(3)
        ->and((int) DB::connection('pgsql_super')->selectOne("SELECT value FROM fixture.counters WHERE name = 'sneaky'")->value)->toBe(0);
})->with([
    'statement break' => ['t; drop'],
    'quote' => ["x' or '1'='1"],
    'drop' => ['users; DROP TABLE fixture.tags; --'],
    'quoted identifier' => ['"users"'],
    'qualified' => ['fixture.users'],
    'case' => ['USERS'],
    'wildcard' => ['%'],
    'glob' => ['*'],
    'call' => ['sneaky() --'],
    'regclass' => ["users'::regclass, (SELECT fixture.sneaky())::text::regclass --"],
    'nul byte' => ["users\0"],
    'invalid utf-8' => ["users\xff"],
    'over 63 bytes' => ['users'.str_repeat('x', 64)],
    'empty' => [''],
]);

it('S12 finds objects whose names look like SQL by their literal name', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.\"x' or '1'='1\" (\"a; drop\" int)");

    expect(array_column(introspector()->objects($schema), 'name'))->toBe(["x' or '1'='1"])
        ->and(array_column(introspector()->columns($schema, "x' or '1'='1"), 'name'))->toBe(['a; drop'])
        ->and(introspector()->tableDdl($schema, "x' or '1'='1"))->toStartWith("CREATE TABLE {$schema}.\"x' or '1'='1\" (\n    \"a; drop\" integer");
});

it('searches object names', function () {
    $results = introspector()->search('POST');

    $first = array_slice(array_column($results, 'name'), 0, 5);
    sort($first);

    // Names starting with the term come before names containing it later.
    expect($first)->toBe(['post_count', 'post_stats', 'post_tags', 'posts', 'posts_id_seq'])
        ->and(array_column($results, 'name'))->toContain('posts', 'post_tags', 'post_count', 'published_posts', 'post_stats')
        ->and(array_column($results, 'name'))->not->toContain('users')
        ->and(array_column(introspector()->search('post', 2), 'name'))->toHaveCount(2)
        ->and(introspector()->search('slugify'))->toBe([[
            'schema' => 'fixture', 'name' => 'slugify', 'type' => 'function', 'signature' => 'slugify(text)', 'parent' => null, 'comment' => null,
        ]])
        // LIKE wildcards in the term are literal.
        ->and(introspector()->search('%'))->toBe([])
        ->and(array_filter(array_column(introspector()->search('t_g'), 'name'), fn (string $name): bool => ! str_contains($name, 't_g')))->toBe([])
        ->and(introspector()->search(''))->toBe([]);
});

it('lists a schema with 500 tables quickly', function () {
    $schema = $this->scratchSchema();

    DB::connection('pgsql_admin')->unprepared(<<<SQL
        DO $$
        BEGIN
            FOR i IN 1..500 LOOP
                EXECUTE format('CREATE TABLE %I.%I (id bigint PRIMARY KEY, name text NOT NULL)', '{$schema}', 'table_' || i);
                EXECUTE format('COMMENT ON TABLE %I.%I IS %L', '{$schema}', 'table_' || i, 'Table ' || i);
            END LOOP;
        END
        $$;
        SQL);

    $introspector = introspector();
    $introspector->objects($schema); // Warm the connection and catalog caches.

    $timings = [];

    foreach (range(1, 5) as $run) {
        $start = hrtime(true);
        $objects = $introspector->objects($schema);
        $timings[] = (hrtime(true) - $start) / 1e6;
    }

    sort($timings);

    if (getenv('DBX_PERF_REPORT')) {
        fwrite(STDERR, sprintf("\nobjects() with 500 tables: median %.1f ms, min %.1f ms\n", $timings[2], $timings[0]));
    }

    expect($objects)->toHaveCount(500)
        ->and($timings[2])->toBeLessThan(1500);
});
