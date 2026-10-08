<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use TacticalNinj4\DbExplorer\Tests\PgsqlFixture;

/**
 * @return array<string, string>
 */
function fixturePairs(string $sql): array
{
    $pairs = [];

    foreach (DB::connection('pgsql_super')->select($sql) as $row) {
        $values = array_values((array) $row);
        $pairs[(string) $values[0]] = (string) $values[1];
    }

    return $pairs;
}

it('creates every fixture object type', function () {
    $fixture = "'fixture'::regnamespace";

    expect(fixturePairs("SELECT relname, relkind FROM pg_class WHERE relnamespace = {$fixture} AND relkind IN ('r', 'p', 'v', 'm', 'S')"))
        ->toMatchArray([
            'users' => 'r',
            'secrets' => 'r',
            'posts' => 'r',
            'tags' => 'r',
            'post_tags' => 'r',
            'event_log' => 'r',
            'counters' => 'r',
            'big' => 'r',
            'measurements' => 'p',
            'measurements_2025' => 'r',
            'measurements_2026' => 'r',
            'published_posts' => 'v',
            'secret_labels' => 'v',
            'post_stats' => 'm',
            'ticket_seq' => 'S',
        ])
        // Enum, domain and standalone composite type (not table row types).
        ->and(fixturePairs("SELECT t.typname, t.typtype FROM pg_type t LEFT JOIN pg_class c ON c.oid = t.typrelid WHERE t.typnamespace = {$fixture} AND (t.typtype IN ('e', 'd') OR c.relkind = 'c') ORDER BY t.typname"))
        ->toBe(['address' => 'c', 'mood' => 'e', 'rating' => 'd'])
        ->and(fixturePairs("SELECT p.proname, l.lanname FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.pronamespace = {$fixture}"))
        ->toMatchArray(['post_count' => 'sql', 'slugify' => 'plpgsql', 'sneaky' => 'plpgsql', 'log_post_change' => 'plpgsql'])
        ->and(fixturePairs("SELECT tgname, tgrelid::regclass::text FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = 'fixture.posts'::regclass"))
        ->toBe(['posts_log_change' => 'fixture.posts'])
        ->and(fixturePairs("SELECT extname, extnamespace::regnamespace::text FROM pg_extension WHERE extnamespace = {$fixture}"))
        ->toBe(['citext' => 'fixture']);
});

it('creates the constraints, indexes and comments', function () {
    $constraints = fixturePairs("SELECT conname, contype::text || ':' || coalesce(array_length(conkey, 1), 0) FROM pg_constraint WHERE connamespace = 'fixture'::regnamespace");
    $indexes = fixturePairs("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'fixture'");

    expect($constraints)->toMatchArray([
        'posts_price_check' => 'c:1',
        'posts_uuid_unique' => 'u:1',
        'users_email_key' => 'u:1',
        'posts_user_id_fkey' => 'f:1',
        'post_tags_post_id_fkey' => 'f:1',
        'post_tags_pkey' => 'p:2',
        'measurements_pkey' => 'p:2',
        'big_pkey' => 'p:1',
        'rating_check' => 'c:0',
    ])
        ->and(DB::connection('pgsql_super')->select("SELECT 1 FROM pg_constraint WHERE conrelid = 'fixture.event_log'::regclass AND contype = 'p'"))->toBe([])
        ->and($indexes['posts_published_at_idx'])->toContain('WHERE (published_at IS NOT NULL)')
        ->and($indexes['users_lower_name_idx'])->toContain('(lower(name))')
        ->and($indexes['posts_meta_idx'])->toContain('USING gin')
        ->and(fixturePairs("SELECT 'users', obj_description('fixture.users'::regclass, 'pg_class')"))->toBe(['users' => 'People who write posts'])
        ->and(fixturePairs("SELECT 'email', col_description('fixture.users'::regclass, 2)"))->toBe(['email' => 'Case-insensitive login']);
});

it('has columns of every type the explorer must render', function () {
    $columns = fn (string $table) => fixturePairs("SELECT attname, format_type(atttypid, atttypmod) FROM pg_attribute WHERE attrelid = '{$table}'::regclass AND attnum > 0 AND NOT attisdropped");

    expect($columns('fixture.posts'))->toMatchArray([
        'uuid' => 'uuid',
        'body' => 'text',
        'tags' => 'text[]',
        'meta' => 'jsonb',
        'attachment' => 'bytea',
        'price' => 'numeric(12,2)',
        'rating' => 'fixture.rating',
        'published_at' => 'timestamp with time zone',
    ])
        ->and($columns('fixture.users'))->toMatchArray([
            'email' => 'fixture.citext',
            'password' => 'text',
            'api_token' => 'text',
            'mood' => 'fixture.mood',
            'address' => 'fixture.address',
        ])
        ->and((int) DB::connection('pgsql_super')->selectOne("SELECT length(body) AS n FROM fixture.posts WHERE title = 'Post 1'")->n)->toBeGreaterThan(50000);
});

it('fills fixture.big with 1,000,000 rows', function () {
    expect((int) DB::connection('pgsql_super')->selectOne('SELECT count(*) AS n FROM fixture.big')->n)->toBe(1000000);
});

it('lets the viewer read the fixture but not write to it', function () {
    $viewer = DB::connection('pgsql_viewer');

    expect($viewer->selectOne('SELECT current_user AS u')->u)->toBe('dbx_viewer')
        ->and((int) $viewer->selectOne('SELECT count(*) AS n FROM fixture.users')->n)->toBe(3)
        ->and((int) $viewer->selectOne('SELECT count(*) AS n FROM fixture.published_posts')->n)->toBeGreaterThan(0)
        ->and(sqlState(fn () => $viewer->update('UPDATE fixture.users SET name = name')))->toBe('42501')
        ->and(sqlState(fn () => $viewer->insert("INSERT INTO fixture.tags (id, name) VALUES (99, 'x')")))->toBe('42501')
        ->and(sqlState(fn () => $viewer->delete('DELETE FROM fixture.big')))->toBe('42501');
});

it('rejects the side-effecting sneaky() for the viewer', function () {
    expect(sqlState(fn () => DB::connection('pgsql_viewer')->select('SELECT fixture.sneaky()')))->toBe('42501');
});

it('lets the editor change rows but not the schema', function () {
    $editor = DB::connection('pgsql_editor');

    $editor->beginTransaction();

    try {
        expect($editor->update("UPDATE fixture.users SET name = 'Alicia' WHERE id = 1"))->toBe(1)
            ->and($editor->insert("INSERT INTO fixture.posts (user_id, uuid, title, body) VALUES (1, '00000000-0000-0000-0000-000000000001', 'New', 'Body')"))->toBeTrue()
            ->and($editor->selectOne('SELECT fixture.sneaky() AS v')->v)->toBe(1);
    } finally {
        $editor->rollBack();
    }

    expect(sqlState(fn () => $editor->statement('CREATE TABLE fixture.x (id int)')))->toBe('42501')
        ->and(sqlState(fn () => $editor->statement('DROP TABLE fixture.big')))->toBe('42501')
        ->and((int) $editor->selectOne("SELECT value FROM fixture.counters WHERE name = 'sneaky'")->value)->toBe(0);
});

it('makes dbx_admin the owner of the fixture', function () {
    $owners = DB::connection('pgsql_admin')->select(
        "SELECT DISTINCT pg_get_userbyid(relowner) AS owner FROM pg_class WHERE relnamespace = 'fixture'::regnamespace AND relkind IN ('r', 'p', 'v', 'm', 'S')",
    );

    expect(array_column($owners, 'owner'))->toBe(['dbx_admin'])
        ->and(config('dbexplorer.connections.main'))->toBe(['viewer' => 'pgsql_viewer', 'editor' => 'pgsql_editor', 'admin' => 'pgsql_admin'])
        ->and(DB::connection('pgsql_admin')->selectOne('SELECT current_user AS u')->u)->toBe('dbx_admin');
});

it('gives a test a scratch schema the roles can use and drops it afterwards', function () {
    $schema = $this->scratchSchema();

    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.notes (id bigserial PRIMARY KEY, body text NOT NULL)");
    DB::connection('pgsql_editor')->insert("INSERT INTO {$schema}.notes (body) VALUES (?)", ['hello']);

    $exists = fn () => DB::connection('pgsql_super')->select('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schema]) !== [];

    expect($schema)->toMatch('/^t_[0-9a-f]{12}$/')
        ->and($this->scratchSchema())->toBe($schema)
        ->and(DB::connection('pgsql_viewer')->selectOne("SELECT body FROM {$schema}.notes")->body)->toBe('hello')
        ->and(sqlState(fn () => DB::connection('pgsql_viewer')->delete("DELETE FROM {$schema}.notes")))->toBe('42501')
        ->and(sqlState(fn () => DB::connection('pgsql_editor')->statement("CREATE TABLE {$schema}.x (id int)")))->toBe('42501')
        ->and($exists())->toBeTrue();

    $this->dropScratchSchema();

    expect($exists())->toBeFalse();
});

it('loads the fixture once and keeps it across tests and runs', function () {
    $pdo = DB::connection('pgsql_super')->getPdo();
    $createdBy = fn () => DB::connection('pgsql_super')->selectOne("SELECT xmin::text AS x FROM pg_class WHERE oid = 'fixture.big'::regclass")->x;
    $before = $createdBy();

    Artisan::call('migrate:fresh');

    expect(PgsqlFixture::marker($pdo))->toBe(PgsqlFixture::version())
        // What the next run's setup does: the marker matches, so nothing reloads.
        ->and(PgsqlFixture::load($pdo))->toBeFalse()
        ->and($createdBy())->toBe($before)
        ->and(DB::connection('pgsql_super')->select("SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = 'dbx_audit_log'"))->not->toBe([]);
});
