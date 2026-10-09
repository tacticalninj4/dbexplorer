<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\SavedQuery;
use TacticalNinj4\DbExplorer\Sql\NamedParameters;
use TacticalNinj4\DbExplorer\Tests\TestCase;

// M4.1: the saved queries API. Runs against Postgres live in
// tests/Pgsql/SavedQueryRunTest.php; NamedParameters is pure PHP.

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
        'staging' => ['viewer' => 'staging_readonly'],
    ]]);

    $this->artisan('migrate');
});

/**
 * Call a queries endpoint as user $user with $role on `main`.
 *
 * @param  array<string, mixed>  $body
 */
function queriesAs(TestCase $test, string $method, string $uri, array $body = [], string $role = 'viewer', int $user = 1): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser($user))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->json($method, '/dbexplorer/api/main/'.ltrim($uri, '/'), $body);
}

it('lists the user\'s own saved queries and the shared ones, per label', function () {
    SavedQuery::query()->create(['user_id' => '1', 'label' => 'main', 'name' => 'Mine', 'sql' => 'SELECT 1']);
    SavedQuery::query()->create(['user_id' => '1', 'label' => 'main', 'name' => 'Shared', 'sql' => 'SELECT 2', 'visibility' => 'shared']);
    SavedQuery::query()->create(['user_id' => '2', 'label' => 'main', 'name' => 'Theirs', 'sql' => 'SELECT 3']);
    SavedQuery::query()->create(['user_id' => '2', 'label' => 'main', 'name' => 'Their shared', 'sql' => 'SELECT 4', 'visibility' => 'shared']);
    SavedQuery::query()->create(['user_id' => '2', 'label' => 'staging', 'name' => 'Elsewhere', 'sql' => 'SELECT 5', 'visibility' => 'shared']);

    $names = array_column(queriesAs($this, 'GET', 'queries')->assertOk()->json('data'), 'name');

    expect($names)->toBe(['Mine', 'Shared', 'Their shared'])
        ->and(array_column(queriesAs($this, 'GET', 'queries', [], 'viewer', 2)->assertOk()->json('data'), 'name'))->toBe(['Shared', 'Their shared', 'Theirs']);
});

it('saves a query with typed parameters and tags', function () {
    $data = queriesAs($this, 'POST', 'queries', [
        'name' => 'Posts by author',
        'description' => 'Count per author',
        'sql' => 'SELECT count(*) FROM posts WHERE author_id = :author AND created_at >= :since',
        'params' => [
            ['name' => 'author', 'type' => 'int'],
            ['name' => 'since', 'type' => 'date', 'required' => false],
        ],
        'tags' => ['reports'],
        'visibility' => 'shared',
    ], 'editor')->assertStatus(201)->json('data');

    expect($data['owned'])->toBeTrue()
        ->and($data['params'][1]['type'])->toBe('date');

    $model = SavedQuery::query()->sole();

    expect($model->label)->toBe('main')
        ->and($model->user_id)->toBe('1');
});

it('lets only the owner update or delete, through 404s', function () {
    $mine = SavedQuery::query()->create(['user_id' => '1', 'label' => 'main', 'name' => 'Mine', 'sql' => 'SELECT 1', 'visibility' => 'shared']);
    $theirs = SavedQuery::query()->create(['user_id' => '2', 'label' => 'main', 'name' => 'Theirs', 'sql' => 'SELECT 2', 'visibility' => 'shared']);

    // Shared queries are visible to others, but not theirs to change.
    queriesAs($this, 'PATCH', "queries/{$theirs->id}", ['name' => 'Stolen'], 'viewer', 1)->assertNotFound();
    queriesAs($this, 'DELETE', "queries/{$theirs->id}", [], 'viewer', 1)->assertNotFound();

    queriesAs($this, 'PATCH', "queries/{$mine->id}", ['name' => 'Renamed', 'visibility' => 'private'], 'viewer', 1)->assertOk();
    queriesAs($this, 'DELETE', "queries/{$mine->id}", [], 'viewer', 1)->assertNoContent();

    expect(SavedQuery::query()->count())->toBe(1);
});

it('rewrites :name placeholders to bindings, and leaves literals and casts alone', function () {
    [$sql, $bindings] = NamedParameters::bind(
        "SELECT ':not_a_param' AS a, -- :commented\n  id::text, title FROM t WHERE id = :id AND kind = :kind",
        [['name' => 'id', 'type' => 'int'], ['name' => 'kind', 'type' => 'enum', 'options' => ['a', 'b']]],
        ['id' => '7', 'kind' => 'a'],
    );

    expect($sql)->toBe("SELECT ':not_a_param' AS a, -- :commented\n  id::text, title FROM t WHERE id = ? AND kind = ?")
        ->and($bindings)->toBe([7, 'a']);
});

it('casts and validates values per parameter type', function () {
    [, $dateBindings] = NamedParameters::bind('SELECT :d', [['name' => 'd', 'type' => 'date']], ['d' => '2026-03-05 09:00']);

    expect($dateBindings)->toBe(['2026-03-05']);

    NamedParameters::bind('SELECT :x', [['name' => 'x', 'type' => 'int']], ['x' => 'abc']);
})->throws(InvalidArgumentException::class, 'whole number');

it('rejects unknown placeholders, missing values and enum values out of list', function (array $params, array $values) {
    NamedParameters::bind('SELECT :x', $params, $values);
})->throws(InvalidArgumentException::class)->with([
    'unknown placeholder' => [[['name' => 'other']], []],
    'missing required' => [[['name' => 'x']], []],
    'enum out of list' => [[['name' => 'x', 'type' => 'enum', 'options' => ['a']]], ['x' => 'z']],
    'not a scalar' => [[['name' => 'x']], ['x' => ['nested']]],
]);

it('keeps optional parameters as null bindings, not errors', function () {
    [, $bindings] = NamedParameters::bind('SELECT :x', [['name' => 'x', 'required' => false]], []);

    expect($bindings)->toBe([null]);
});
