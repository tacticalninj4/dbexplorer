<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use TacticalNinj4\DbExplorer\Drivers\Postgres\Introspector;
use TacticalNinj4\DbExplorer\Drivers\Postgres\RowsQuery;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Security\Masker;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

/**
 * Build a page with RowsQuery for a relation as the viewer's login sees it,
 * run it directly on pgsql_viewer, and work out the next page's parameters
 * the way the rows endpoint does.
 *
 * @param  array<string, mixed>  $params
 * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null, 2: array<string, mixed>}
 */
function rowsPage(string $schema, string $name, array $params = []): array
{
    $source = (new Introspector(DB::connection('pgsql_viewer')))->rowSource($schema, $name);
    [$sql, $bindings, $meta] = (new RowsQuery($schema, $name, $source['columns'], $source['key'], $source['ctid']))->build($params, 1000);

    $rows = array_map(fn (object $row): array => (array) $row, DB::connection('pgsql_viewer')->select($sql, $bindings));
    $more = count($rows) > $meta['limit'];
    $rows = array_slice($rows, 0, $meta['limit']);
    $next = null;

    if ($more && $meta['pagination'] === 'keyset') {
        $last = $rows[count($rows) - 1];
        $values = array_map(fn (string $column): mixed => $last[$column], $meta['cursor_columns']);
        $next = ['cursor' => RowsQuery::encodeCursor($values, $meta['sort'], $meta['direction'])] + $params;
    } elseif ($more) {
        $next = ['offset' => $meta['offset'] + $meta['limit']] + $params;
    }

    return [$rows, $next, $meta];
}

/**
 * Follow next pages from $params, at most $pages of them.
 *
 * @param  array<string, mixed>  $params
 * @return array{0: list<array<string, mixed>>, 1: int}
 */
function rowsPages(string $schema, string $name, array $params, int $pages = 100): array
{
    $all = [];
    $count = 0;

    do {
        [$rows, $params] = rowsPage($schema, $name, $params);
        $all = array_merge($all, $rows);
        $count++;
    } while ($params !== null && $count < $pages);

    return [$all, $count];
}

/**
 * @return list<mixed>
 */
function pluckIds(string $sql, string $column = 'id'): array
{
    return array_column(array_map(fn (object $row): array => (array) $row, DB::connection('pgsql_viewer')->select($sql)), $column);
}

it('describes how each kind of relation is paged', function (string $name, string $type, ?array $key, bool $ctid) {
    $source = (new Introspector(DB::connection('pgsql_viewer')))->rowSource('fixture', $name);

    expect($source['type'])->toBe($type)
        ->and($source['key'])->toBe($key)
        ->and($source['ctid'])->toBe($ctid);
})->with([
    'primary key' => ['big', 'table', ['id'], true],
    'primary key over a unique column' => ['users', 'table', ['id'], true],
    'composite primary key' => ['post_tags', 'table', ['post_id', 'tag_id'], true],
    'no key' => ['event_log', 'table', null, true],
    'partitioned table' => ['measurements', 'partitioned_table', ['id', 'recorded_on'], false],
    'partition' => ['measurements_2025', 'table', ['id', 'recorded_on'], true],
    'view' => ['published_posts', 'view', null, false],
    'materialized view' => ['post_stats', 'materialized_view', null, true],
]);

it('estimates row counts from reltuples, except for views', function () {
    $introspector = new Introspector(DB::connection('pgsql_viewer'));

    expect($introspector->rowSource('fixture', 'big')['estimated_rows'])->toBeGreaterThan(900000)
        ->and($introspector->rowSource('fixture', 'measurements')['estimated_rows'])->toBe(730)
        ->and($introspector->rowSource('fixture', 'published_posts')['estimated_rows'])->toBeNull();
});

it('uses a unique index on NOT NULL columns as the key, but not a nullable or partial one', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->unprepared(<<<SQL
        CREATE TABLE {$schema}.a (code text NOT NULL, note text);
        CREATE UNIQUE INDEX ON {$schema}.a (code);
        CREATE TABLE {$schema}.b (code text, note text NOT NULL);
        CREATE UNIQUE INDEX ON {$schema}.b (code);
        CREATE UNIQUE INDEX ON {$schema}.b (note) WHERE note <> '';
        SQL);

    $introspector = new Introspector(DB::connection('pgsql_viewer'));

    expect($introspector->rowSource($schema, 'a')['key'])->toBe(['code'])
        ->and($introspector->rowSource($schema, 'b')['key'])->toBeNull();
});

it('leaves columns the login cannot SELECT out of the row source', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->unprepared(<<<SQL
        CREATE TABLE {$schema}.accounts (id int PRIMARY KEY, email text NOT NULL UNIQUE, password text);
        REVOKE SELECT ON {$schema}.accounts FROM dbx_viewer;
        GRANT SELECT (email, password) ON {$schema}.accounts TO dbx_viewer;
        REVOKE SELECT (password) ON {$schema}.accounts FROM dbx_viewer;
        INSERT INTO {$schema}.accounts VALUES (1, 'a@example.com', 'x');
        SQL);

    $source = (new Introspector(DB::connection('pgsql_viewer')))->rowSource($schema, 'accounts');
    [$rows] = rowsPage($schema, 'accounts');

    expect(array_column($source['columns'], 'name'))->toBe(['email'])
        ->and($source['key'])->toBe(['email'])
        ->and($rows)->toBe([['email' => 'a@example.com']]);
});

it('S12 pages fixture.big by key with no duplicates or gaps across page boundaries', function (string $direction, int $first) {
    [$rows, $pages] = rowsPages('fixture', 'big', ['limit' => '1000', 'direction' => $direction], 3);
    $ids = array_column($rows, 'id');
    $expected = $direction === 'asc' ? range($first, $first + 2999) : range($first, $first - 2999);

    expect($pages)->toBe(3)->and($ids)->toBe($expected);
})->with([['asc', 1], ['desc', 1000000]]);

it('S12 pages a non-key sort column with the key as tiebreaker', function (string $direction) {
    $params = ['limit' => '700', 'sort' => 'n', 'direction' => $direction, 'filters' => [['column' => 'n', 'op' => 'in', 'value' => ['1', '2']]]];
    [$rows, $pages] = rowsPages('fixture', 'big', $params);

    expect($pages)->toBe(3)
        ->and(array_column($rows, 'id'))->toBe(pluckIds("SELECT id FROM fixture.big WHERE n IN (1, 2) ORDER BY n {$direction}, id {$direction}"));
})->with(['asc', 'desc']);

it('S12 pages a nullable sort column through the NULL tail, which always comes last', function (string $direction) {
    [$rows, $pages] = rowsPages('fixture', 'posts', ['limit' => '7', 'sort' => 'published_at', 'direction' => $direction]);
    $ids = array_column($rows, 'id');

    expect($pages)->toBe(8)
        ->and($ids)->toBe(pluckIds("SELECT id FROM fixture.posts ORDER BY published_at {$direction} NULLS LAST, id {$direction}"))
        ->and(array_slice(array_column($rows, 'published_at'), -12))->each->toBeNull();
})->with(['asc', 'desc']);

it('S12 pages a composite primary key', function () {
    [$rows, $pages] = rowsPages('fixture', 'post_tags', ['limit' => '6']);
    $pairs = array_map(fn (array $row): string => $row['post_id'].'-'.$row['tag_id'], $rows);

    expect($pages)->toBeGreaterThan(3)
        ->and($pairs)->toBe(array_map(
            fn (object $row): string => $row->post_id.'-'.$row->tag_id,
            DB::connection('pgsql_viewer')->select('SELECT post_id, tag_id FROM fixture.post_tags ORDER BY post_id, tag_id'),
        ));
});

it('pages a table without a key by offset', function () {
    [$first, , $meta] = rowsPage('fixture', 'event_log', ['limit' => '4']);
    [$rows, $pages] = rowsPages('fixture', 'event_log', ['limit' => '4']);

    expect($meta['pagination'])->toBe('offset')
        ->and($first)->toHaveCount(4)
        ->and($pages)->toBe(3)
        ->and(array_column($rows, 'post_id'))->toBe(range(1, 10));
});

it('pages a view by offset', function () {
    [$rows, $pages] = rowsPages('fixture', 'published_posts', ['limit' => '10', 'sort' => 'id']);

    expect($pages)->toBe(4)->and(array_column($rows, 'id'))->toBe(pluckIds('SELECT id FROM fixture.published_posts ORDER BY id'));
});

it('S12 returns exactly the rows the filters match', function (array $filters, string $where) {
    [$rows] = rowsPages('fixture', 'posts', ['filters' => $filters]);

    expect(array_column($rows, 'id'))->toBe(pluckIds("SELECT id FROM fixture.posts WHERE {$where} ORDER BY id"))
        ->and($rows)->not->toBeEmpty();
})->with([
    'comparison and ilike' => [[['column' => 'price', 'op' => '>=', 'value' => '10'], ['column' => 'title', 'op' => 'ilike', 'value' => '%1%']], "price >= 10 AND title ILIKE '%1%'"],
    '!=' => [[['column' => 'user_id', 'op' => '!=', 'value' => '1']], 'user_id <> 1'],
    'in' => [[['column' => 'id', 'op' => 'in', 'value' => ['1', '3', '5', '999']]], 'id IN (1, 3, 5)'],
    'is_null' => [[['column' => 'published_at', 'op' => 'is_null']], 'published_at IS NULL'],
    'not_null' => [[['column' => 'published_at', 'op' => 'not_null']], 'published_at IS NOT NULL'],
    'timestamptz' => [[['column' => 'published_at', 'op' => '<', 'value' => '2026-02-10 00:00:00+00']], "published_at < '2026-02-10 00:00:00+00'"],
    'uuid' => [[['column' => 'uuid', 'op' => '=', 'value' => md5('post-7')]], "uuid = md5('post-7')::uuid"],
    'array as text' => [[['column' => 'tags', 'op' => 'like', 'value' => '%tag1%']], "tags::text LIKE '%tag1%'"],
    'jsonb' => [[['column' => 'meta', 'op' => '=', 'value' => '{"n": 2, "even": true, "nested": {"k": "v2"}}']], 'id = 2'],
    'domain' => [[['column' => 'rating', 'op' => '=', 'value' => '3']], 'rating = 3'],
    'numeric' => [[['column' => 'price', 'op' => '<', 'value' => '3.75']], 'price < 3.75'],
    'bytea not null' => [[['column' => 'attachment', 'op' => 'not_null']], 'attachment IS NOT NULL'],
]);

it('S12 counts the filtered rows exactly on request', function () {
    $source = (new Introspector(DB::connection('pgsql_viewer')))->rowSource('fixture', 'big');
    [, , $meta] = (new RowsQuery('fixture', 'big', $source['columns'], $source['key'], $source['ctid']))
        ->build(['exact' => '1', 'filters' => [['column' => 'n', 'op' => '=', 'value' => '7']]], 1000);

    expect(DB::connection('pgsql_viewer')->selectOne($meta['count']['sql'], $meta['count']['bindings'])->count)->toBe(1000);
});

it('S12 sorts and filters on odd column names', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->unprepared(<<<SQL
        CREATE TABLE {$schema}."odd table" ("key ""q""" int PRIMARY KEY, "ünï;val" text);
        INSERT INTO {$schema}."odd table" SELECT g, 'v' || (g % 3) FROM generate_series(1, 9) g;
        SQL);

    [$rows, $pages] = rowsPages($schema, 'odd table', [
        'limit' => '2',
        'sort' => 'ünï;val',
        'direction' => 'desc',
        'filters' => [['column' => 'key "q"', 'op' => '>', 'value' => '2']],
    ]);

    expect($pages)->toBe(4)->and(array_column($rows, 'key "q"'))->toBe([8, 5, 7, 4, 9, 6, 3]);
});

// HTTP: validation and lookups happen before any row query, which then runs
// through the QueryRunner.

it('S12 rejects injection attempts with 422 before any row query runs', function (string $query, string $field) {
    app()['env'] = 'local';
    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $this->actingAs(makeUser())
        ->getJson('/dbexplorer/api/main/rows/fixture/posts?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(implode("\n", $queries))->not->toContain('fixture"."posts')->not->toContain('DROP')->not->toContain('pg_sleep');
})->with([
    'sort statement' => ['sort='.rawurlencode('id; DROP TABLE fixture.posts'), 'sort'],
    'sort expression list' => ['sort='.rawurlencode('id desc, (select pg_sleep(5))'), 'sort'],
    'sort direction' => ['sort=id&direction='.rawurlencode('desc; DROP TABLE x'), 'direction'],
    'filter column' => ['filters[0][column]='.rawurlencode('id) OR (1=1').'&filters[0][op]==&filters[0][value]=1', 'filters.0.column'],
    'filter operator' => ['filters[0][column]=id&filters[0][op]='.rawurlencode('= 1 OR 1=1 --').'&filters[0][value]=1', 'filters.0.op'],
    'unknown operator' => ['filters[0][column]=id&filters[0][op]=between&filters[0][value]=1', 'filters.0.op'],
    'cursor' => ['cursor='.rawurlencode("eyJ2IjpbMV19'; DROP TABLE x"), 'cursor'],
    'forged cursor' => ['cursor='.RowsQuery::encodeCursor(['1'], 'title', 'asc'), 'cursor'],
    'limit over the role cap' => ['limit=1001', 'limit'],
]);

it('S14 rejects sorting or filtering by a masked column', function (string $query, string $field) {
    app()['env'] = 'local';

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/rows/fixture/users?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    ['sort=password', 'sort'],
    ['filters[0][column]=api_token&filters[0][op]=like&filters[0][value]=tok_a%25', 'filters.0.column'],
]);

it('bounds the page size by the role row limit', function () {
    app()['env'] = 'local';
    config(['dbexplorer.limits.editor.rows' => 50]);
    DbExplorer::roleUsing(fn (): string => 'editor');

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/rows/fixture/posts?limit=51')->assertStatus(422)->assertJsonValidationErrors('limit');
    expect($this->actingAs(makeUser())->getJson('/dbexplorer/api/main/rows/fixture/posts?limit=50')->assertOk()->json('data.rows'))->toHaveCount(50);
});

it('answers 404 for relations that have no rows to browse', function (string $name) {
    app()['env'] = 'local';

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/rows/fixture/'.rawurlencode($name))
        ->assertNotFound()
        ->assertExactJson(['message' => 'The database object does not exist.']);
})->with(['missing' => 'nope', 'sequence' => 'ticket_seq', 'type' => 'mood', 'function' => 'post_count']);

/**
 * GET a rows page through the API as $role.
 *
 * @return array<string, mixed>
 */
function rowsApi(PgsqlTestCase $test, string $path, string $role = 'viewer'): array
{
    app()['env'] = 'local';
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())->getJson('/dbexplorer/api/main/rows/'.$path)->assertOk()->json('data');
}

it('S14 serves rows with masked columns masked for every role', function (string $role) {
    $data = rowsApi($this, 'fixture/users?limit=2', $role);
    $columns = array_column($data['columns'], 'masked', 'name');

    expect($columns)->toMatchArray(['id' => false, 'email' => false, 'password' => true, 'api_token' => true])
        ->and($data['rows'])->toHaveCount(2)
        ->and($data['rows'][0][1])->toBe('Alice@Example.com')
        ->and($data['rows'][0][3])->toBe(Masker::MASK)
        ->and($data['rows'][0][4])->toBe(Masker::MASK)
        ->and($data['pagination'])->toBe('keyset')
        ->and($data['truncated'])->toBeTrue()
        ->and($data['count'])->toBe(['value' => 3, 'exact' => false])
        ->and(json_encode($data))->not->toContain('aliceHash')->not->toContain('tok_alice');
})->with(['viewer', 'editor', 'admin']);

it('S12 pages fixture.big through the API by cursor with no duplicates or gaps', function () {
    $ids = [];
    $query = 'limit=1000';

    for ($page = 0; $page < 3; $page++) {
        $data = rowsApi($this, 'fixture/big?'.$query);
        $ids = array_merge($ids, array_column($data['rows'], 0));
        $query = 'limit=1000&cursor='.$data['next_cursor'];
    }

    expect($ids)->toBe(range(1, 3000));
});

it('pages a nullable sort column through the API to the last page', function () {
    $ids = [];
    $query = 'limit=9&sort=published_at&direction=desc';
    $pages = 0;

    do {
        $data = rowsApi($this, 'fixture/posts?'.$query);
        $ids = array_merge($ids, array_column($data['rows'], 0));
        $query = 'limit=9&sort=published_at&direction=desc&cursor='.$data['next_cursor'];
        $pages++;
    } while ($data['next_cursor'] !== null);

    expect($pages)->toBe(6)
        ->and($data['truncated'])->toBeFalse()
        ->and($ids)->toBe(pluckIds('SELECT id FROM fixture.posts ORDER BY published_at DESC NULLS LAST, id DESC'));
});

it('pages a table without a key through the API by offset', function () {
    $first = rowsApi($this, 'fixture/event_log?limit=6');
    $second = rowsApi($this, 'fixture/event_log?limit=6&offset='.$first['next_offset']);

    expect($first['pagination'])->toBe('offset')
        ->and($first['next_offset'])->toBe(6)
        ->and($second['next_offset'])->toBeNull()
        ->and(array_merge(array_column($first['rows'], 1), array_column($second['rows'], 1)))->toBe(range(1, 10));
});

it('S17 audits each row query and counts exactly on request', function () {
    $data = rowsApi($this, 'fixture/big?limit=5&exact=1&filters[0][column]=n&filters[0][op]==&filters[0][value]=7');

    $entries = AuditEntry::query()->where('action', 'query')->orderBy('id')->get();

    expect($data['count'])->toBe(['value' => 1000, 'exact' => true])
        ->and($entries)->toHaveCount(2)
        ->and($entries->pluck('label')->all())->toBe(['main', 'main'])
        ->and($entries->pluck('role')->all())->toBe(['viewer', 'viewer'])
        ->and($entries[0]->sql)->toContain('FROM "fixture"."big" WHERE ("n" = ?)')
        ->and($entries[1]->sql)->toStartWith('SELECT count(*)');
});
