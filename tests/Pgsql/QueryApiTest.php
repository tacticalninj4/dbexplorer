<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\QueryHistory;
use TacticalNinj4\DbExplorer\Security\Masker;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;
use TacticalNinj4\DbExplorer\Tests\PgsqlFixture;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

beforeEach(function () {
    app()['env'] = 'local';
});

/**
 * POST to /dbexplorer/api/main/query as user $user with $role on `main`,
 * with the session's CSRF token, as the SPA sends it.
 *
 * @param  array<string, mixed>  $body
 */
function queryAs(PgsqlTestCase $test, string $role, array $body, int $user = 1): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser($user))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/query', $body);
}

it('runs a SELECT for a viewer and returns the result with its metadata', function () {
    $data = queryAs($this, 'viewer', ['sql' => 'SELECT id, name FROM fixture.users WHERE id <= ? ORDER BY id', 'bindings' => [2]])
        ->assertOk()
        ->json('data');

    expect($data)->toMatchArray([
        'columns' => [['name' => 'id', 'type' => 'int8', 'masked' => false], ['name' => 'name', 'type' => 'text', 'masked' => false]],
        'rows' => [[1, 'Alice'], [2, 'Bob']],
        'truncated' => false,
        'row_count' => 2,
        'classification' => 'read',
        'explain' => null,
        'plan' => null,
    ])
        ->and($data['duration_ms'])->toBeNumeric()
        ->and($data['notices'])->toBeArray()
        ->and($data)->toHaveKey('notices_supported');
});

it('rejects a query without the session CSRF token', function () {
    $this->actingAs(makeUser())->postJson('/dbexplorer/api/main/query', ['sql' => 'SELECT 1'])->assertStatus(419);

    expect(AuditEntry::query()->count())->toBe(0);
});

it('binds named values', function () {
    expect(queryAs($this, 'viewer', ['sql' => 'SELECT name FROM fixture.users WHERE id = :id', 'bindings' => ['id' => 3]])->assertOk()->json('data.rows'))
        ->toBe([['Carol']]);
});

it('S9 answers a viewer UPDATE with 422 and SQLSTATE 25006, even on a login with write grants', function () {
    config(['dbexplorer.connections.main.viewer' => 'pgsql_editor']);

    queryAs($this, 'viewer', ['sql' => "UPDATE fixture.counters SET value = value + 1 WHERE name = 'visits'", 'confirm' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.sqlstate', '25006');

    expect(DB::connection('pgsql_super')->selectOne("SELECT value FROM fixture.counters WHERE name = 'visits'")->value)->toBe(42);
});

it('S8 rejects multiple statements with 422 and runs none of them', function (string $sql) {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.keep (id int)");

    // The DROP makes it destructive: confirm the password so the database gets to refuse it.
    $this->withSession([PasswordConfirmation::SESSION_KEY => time()]);

    queryAs($this, 'admin', ['sql' => str_replace('{schema}', $schema, $sql), 'confirm' => true])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['message', 'sqlstate', 'position']]);

    expect(DB::connection('pgsql_super')->selectOne("SELECT to_regclass('{$schema}.keep') IS NOT NULL AS present")->present)->toBeTrue();
})->with([
    'select then drop' => 'SELECT 1; DROP TABLE {schema}.keep',
    'comment between' => "SELECT 1;--\nDROP TABLE {schema}.keep",
    'commit escape' => 'COMMIT; DROP TABLE {schema}.keep',
]);

it('S10 answers a query over the role timeout with 422 and SQLSTATE 57014', function () {
    config(['dbexplorer.limits.viewer.timeout' => 1]);

    queryAs($this, 'viewer', ['sql' => 'SELECT pg_sleep(3)'])->assertStatus(422)->assertJsonPath('error.sqlstate', '57014');
});

it('reports the error position in the submitted SQL', function () {
    queryAs($this, 'viewer', ['sql' => "SELECT 1\nFROM nope"])
        ->assertStatus(422)
        ->assertExactJson(['error' => ['message' => 'relation "nope" does not exist', 'sqlstate' => '42P01', 'position' => 15]]);
});

it('S13 answers a query on a hidden table exactly like one on a missing table', function () {
    config(['dbexplorer.hidden' => ['fixture.secrets']]);

    $hidden = queryAs($this, 'admin', ['sql' => 'SELECT * FROM fixture.secrets'])->assertStatus(422)->json();
    $missing = queryAs($this, 'admin', ['sql' => 'SELECT * FROM fixture.secretz'])->assertStatus(422)->json();

    expect($hidden['error']['sqlstate'])->toBe('42P01')
        ->and(str_replace('secrets', 'secretz', (string) json_encode($hidden)))->toBe(json_encode($missing));
});

it('S14 masks columns unless an admin unmasks them', function () {
    $sql = ['sql' => 'SELECT name, password FROM fixture.users WHERE id = 1'];

    expect(queryAs($this, 'admin', $sql)->assertOk()->json('data.rows'))->toBe([['Alice', Masker::MASK]])
        ->and(queryAs($this, 'admin', $sql + ['unmask' => true])->assertOk()->json('data'))
        ->toMatchArray(['rows' => [['Alice', '$2y$12$aliceHashAliceHashAliceHashAliceHashAliceHashAliceHa']]]);

    $audit = AuditEntry::query()->where('action', 'query')->orderByDesc('id')->firstOrFail();

    expect($audit->meta)->toMatchArray(['unmasked' => true])->and($audit->role)->toBe('admin');
});

it('S14 forbids unmasking for viewers and editors', function (string $role) {
    queryAs($this, $role, ['sql' => 'SELECT password FROM fixture.users', 'unmask' => true])->assertForbidden();

    expect(AuditEntry::query()->where('action', 'query')->count())->toBe(0);
})->with(['viewer', 'editor']);

it('explains a statement without running it, or with ANALYZE rolled back', function (string $mode) {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.t AS SELECT 1 AS id");

    $data = queryAs($this, 'admin', ['sql' => "DELETE FROM {$schema}.t", 'explain' => $mode])->assertOk()->json('data');

    expect($data['explain'])->toBe($mode)
        ->and($data['plan'][0]['Plan']['Node Type'])->toBe('ModifyTable')
        ->and(isset($data['plan'][0]['Execution Time']))->toBe($mode === 'analyze')
        ->and(DB::connection('pgsql_super')->selectOne("SELECT count(*) AS n FROM {$schema}.t")->n)->toBe(1);
})->with(['plain', 'analyze']);

it('validates the query request', function (array $body, string $field) {
    config(['dbexplorer.limits.sql_length' => 20]);

    queryAs($this, 'viewer', $body)->assertStatus(422)->assertJsonValidationErrors($field);

    expect(AuditEntry::query()->count())->toBe(0);
})->with([
    'missing sql' => [[], 'sql'],
    'sql not a string' => [['sql' => ['SELECT 1']], 'sql'],
    'sql over the length limit' => [['sql' => 'SELECT '.str_repeat('1', 20)], 'sql'],
    'bindings not a list' => [['sql' => 'SELECT ?', 'bindings' => 'x'], 'bindings'],
    'nested binding' => [['sql' => 'SELECT ?', 'bindings' => [['x']]], 'bindings.0'],
    'mixed bindings' => [['sql' => 'SELECT ?', 'bindings' => ['a' => 1, 0 => 2]], 'bindings'],
    'unknown explain mode' => [['sql' => 'SELECT 1', 'explain' => 'verbose'], 'explain'],
    'unmask not a boolean' => [['sql' => 'SELECT 1', 'unmask' => 'please'], 'unmask'],
]);

it('lists only the current user\'s history on the label, newest first', function () {
    queryAs($this, 'viewer', ['sql' => 'SELECT 1'], 1)->assertOk();
    queryAs($this, 'viewer', ['sql' => 'SELECT nope'], 1)->assertStatus(422);
    queryAs($this, 'viewer', ['sql' => 'SELECT 2 AS other_user'], 2)->assertOk();
    QueryHistory::query()->insert(['user_id' => '1', 'label' => 'other', 'sql' => 'SELECT 3', 'outcome' => 'success', 'created_at' => now()]);

    $mine = $this->actingAs(makeUser(1))->getJson('/dbexplorer/api/main/history')->assertOk()->json();
    $theirs = $this->actingAs(makeUser(2))->getJson('/dbexplorer/api/main/history')->assertOk()->json();

    expect(array_column($mine['data'], 'sql'))->toBe(['SELECT nope', 'SELECT 1'])
        ->and(array_column($mine['data'], 'outcome'))->toBe(['error', 'success'])
        ->and($mine['data'][0]['error'])->toContain('42703')
        ->and($mine['data'][0])->not->toHaveKeys(['user_id', 'label'])
        ->and($mine['meta'])->toBe(['page' => 1, 'per_page' => 50, 'has_more' => false])
        ->and(array_column($theirs['data'], 'sql'))->toBe(['SELECT 2 AS other_user']);
});

it('pages the history', function () {
    QueryHistory::query()->insert(array_map(fn (int $i): array => [
        'user_id' => '1', 'label' => 'main', 'sql' => "SELECT {$i}", 'outcome' => 'success', 'created_at' => now(),
    ], range(1, 51)));

    $first = $this->actingAs(makeUser(1))->getJson('/dbexplorer/api/main/history')->json();
    $second = $this->actingAs(makeUser(1))->getJson('/dbexplorer/api/main/history?page=2')->json();

    expect($first['data'])->toHaveCount(50)
        ->and($first['data'][0]['sql'])->toBe('SELECT 51')
        ->and($first['meta']['has_more'])->toBeTrue()
        ->and(array_column($second['data'], 'sql'))->toBe(['SELECT 1'])
        ->and($second['meta']['has_more'])->toBeFalse();
});

it('S7 never sends connection config in query, rows, history or error responses', function () {
    $secrets = [PgsqlFixture::PASSWORD, PgsqlTestCase::database(), 'pgsql_viewer', 'pgsql_super', 'dbx_viewer@'];

    $responses = [
        queryAs($this, 'viewer', ['sql' => 'SELECT id FROM fixture.users']),
        queryAs($this, 'viewer', ['sql' => 'SELECT * FROM nope']),
        queryAs($this, 'viewer', ['sql' => 'UPDATE fixture.users SET name = name']),
        queryAs($this, 'viewer', ['sql' => 'SELECT 1', 'explain' => 'analyze']),
        $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/rows/fixture/users'),
        $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/history'),
    ];

    // A connection that can't log in: the driver's message names the host and login.
    config(['database.connections.pgsql_viewer.password' => 'sentinel-wrong-password']);
    DB::purge('pgsql_viewer');
    $responses[] = queryAs($this, 'viewer', ['sql' => 'SELECT 1'])->assertStatus(422)->assertJsonPath('error.sqlstate', '08006');

    foreach ($responses as $response) {
        $body = (string) $response->getContent();

        foreach ([...$secrets, 'sentinel-wrong-password', (string) config('database.connections.pgsql_viewer.host').':'] as $secret) {
            expect($body)->not->toContain($secret);
        }
    }
});
