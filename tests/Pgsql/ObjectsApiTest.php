<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

beforeEach(function () {
    app()['env'] = 'local';
});

/**
 * GET an API path under /dbexplorer/api/main as a user with $role on `main`.
 */
function apiAs(PgsqlTestCase $test, string $role, string $path): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())->getJson('/dbexplorer/api/main/'.$path);
}

/**
 * schema/name as path segments.
 */
function objectPath(string $schema, string $name): string
{
    return rawurlencode($schema).'/'.rawurlencode($name);
}

const NOT_FOUND_BODY = '{"message":"The database object does not exist."}';

it('lists schemas and extensions in a data envelope', function (string $role) {
    $schemas = apiAs($this, $role, 'schemas')->assertOk()->json('data');
    $extensions = apiAs($this, $role, 'extensions')->assertOk()->json('data');

    expect(collect($schemas)->firstWhere('name', 'fixture'))->toBe(['name' => 'fixture', 'owner' => 'dbx_admin', 'comment' => 'DbExplorer test fixture'])
        ->and(array_column($schemas, 'name'))->not->toContain('pg_catalog', 'information_schema')
        ->and(collect($extensions)->firstWhere('name', 'citext'))->toMatchArray(['name' => 'citext', 'schema' => 'fixture']);
})->with(['viewer', 'editor', 'admin']);

it('lists the objects of a schema and describes one by name', function (string $role) {
    $objects = apiAs($this, $role, 'schemas/fixture/objects')->assertOk()->json('data');

    expect(array_column($objects, 'type', 'name'))->toMatchArray(['users' => 'table', 'published_posts' => 'view', 'post_count' => 'function'])
        ->and(apiAs($this, $role, 'objects/fixture/users')->assertOk()->json())->toBe(['data' => [
            'schema' => 'fixture',
            'name' => 'users',
            'type' => 'table',
            'signature' => null,
            'parent' => null,
            'comment' => 'People who write posts',
        ]])
        ->and(apiAs($this, $role, 'objects/fixture/measurements_2025')->json('data.parent'))->toBe(['schema' => 'fixture', 'name' => 'measurements']);
})->with(['viewer', 'editor', 'admin']);

it('serves the structure tabs of a table', function (string $tab, string $object, Closure $assert) {
    $assert(apiAs($this, 'viewer', "objects/fixture/{$object}/{$tab}")->assertOk()->json('data'));
})->with([
    'columns' => ['columns', 'users', fn (array $data) => expect(array_column($data, 'name'))->toBe(['id', 'email', 'name', 'password', 'api_token', 'mood', 'address', 'created_at'])],
    'indexes' => ['indexes', 'users', fn (array $data) => expect(array_column($data, 'name'))->toContain('users_pkey', 'users_lower_name_idx')],
    'constraints' => ['constraints', 'posts', fn (array $data) => expect(array_column($data, 'type', 'name'))->toMatchArray(['posts_pkey' => 'primary_key', 'posts_user_id_fkey' => 'foreign_key'])],
    'triggers' => ['triggers', 'posts', fn (array $data) => expect(array_column($data, 'name'))->toBe(['posts_log_change'])],
    'ddl' => ['ddl', 'users', fn (array $data) => expect($data['ddl'])->toStartWith('CREATE TABLE fixture.users (')],
    'stats' => ['stats', 'big', fn (array $data) => expect($data['estimated_rows'])->toBeGreaterThan(900000)],
    'definition' => ['definition', 'published_posts', fn (array $data) => expect($data['definition'])->toContain('published_at IS NOT NULL')],
]);

it('describes a function by its URL-encoded signature', function () {
    expect(apiAs($this, 'viewer', 'functions/fixture/'.rawurlencode('post_count(bigint)'))->assertOk()->json('data'))
        ->toMatchArray(['name' => 'post_count', 'signature' => 'post_count(bigint)', 'type' => 'function', 'returns' => 'bigint']);
});

it('searches object names across schemas', function () {
    $names = array_column(apiAs($this, 'viewer', 'search?q=POST_')->assertOk()->json('data'), 'name');

    expect($names)->toContain('post_count', 'post_stats', 'post_tags')->and($names)->not->toContain('posts');
});

it('validates the search term', function (string $query) {
    apiAs($this, 'viewer', 'search'.$query)->assertStatus(422)->assertJsonValidationErrors('q');
})->with([
    'missing' => '',
    'empty' => '?q=',
    'over 100 characters' => '?q='.str_repeat('a', 101),
    'array' => '?q[]=post',
]);

it('accepts a search term of 100 characters', function () {
    apiAs($this, 'viewer', 'search?q='.str_repeat('ü', 100))->assertOk()->assertExactJson(['data' => []]);
});

it('S13 answers a hidden object with the same 404 body as a missing one', function (string $hidden, string $missing) {
    config(['dbexplorer.hidden' => ['fixture.secrets', 'fixture.slugify', 'fixture.secret_labels']]);

    $hiddenResponse = apiAs($this, 'admin', $hidden)->assertNotFound();
    $missingResponse = apiAs($this, 'admin', $missing)->assertNotFound();

    expect($hiddenResponse->getContent())->toBe(NOT_FOUND_BODY)
        ->and($missingResponse->getContent())->toBe(NOT_FOUND_BODY);
})->with([
    'summary' => ['objects/fixture/secrets', 'objects/fixture/nope'],
    'columns' => ['objects/fixture/secrets/columns', 'objects/fixture/nope/columns'],
    'indexes' => ['objects/fixture/secrets/indexes', 'objects/fixture/nope/indexes'],
    'constraints' => ['objects/fixture/secrets/constraints', 'objects/fixture/nope/constraints'],
    'triggers' => ['objects/fixture/secrets/triggers', 'objects/fixture/nope/triggers'],
    'ddl' => ['objects/fixture/secrets/ddl', 'objects/fixture/nope/ddl'],
    'stats' => ['objects/fixture/secrets/stats', 'objects/fixture/nope/stats'],
    'definition' => ['objects/fixture/secret_labels/definition', 'objects/fixture/nope/definition'],
    'function' => ['functions/fixture/'.rawurlencode('slugify(text)'), 'functions/fixture/'.rawurlencode('nope(text)')],
    'rows' => ['rows/fixture/secrets', 'rows/fixture/nope'],
]);

it('S13 answers a hidden schema with the same 404 body as a missing one', function () {
    config(['dbexplorer.hidden' => ['fixture']]);

    expect(apiAs($this, 'admin', 'schemas/fixture/objects')->assertNotFound()->getContent())->toBe(NOT_FOUND_BODY)
        ->and(apiAs($this, 'admin', 'schemas/nope/objects')->assertNotFound()->getContent())->toBe(NOT_FOUND_BODY)
        ->and(apiAs($this, 'admin', 'objects/fixture/users')->assertNotFound()->getContent())->toBe(NOT_FOUND_BODY)
        ->and(array_column(apiAs($this, 'admin', 'schemas')->json('data'), 'name'))->not->toContain('fixture');
});

it('S13 leaves hidden objects out of listings and search', function () {
    config(['dbexplorer.hidden' => ['fixture.secrets']]);

    expect(array_column(apiAs($this, 'admin', 'schemas/fixture/objects')->json('data'), 'name'))->not->toContain('secrets')
        ->and(array_column(apiAs($this, 'admin', 'search?q=secret')->json('data'), 'name'))->toBe(['secret_labels']);
});

it('answers an object the role\'s login has no privilege on with the same 404 body', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->unprepared("CREATE TABLE {$schema}.private_notes (id int PRIMARY KEY); REVOKE ALL ON {$schema}.private_notes FROM dbx_viewer, dbx_editor;");

    foreach (['', '/columns', '/indexes', '/stats', '/ddl'] as $tab) {
        expect(apiAs($this, 'viewer', "objects/{$schema}/private_notes{$tab}")->assertNotFound()->getContent())->toBe(NOT_FOUND_BODY);
        apiAs($this, 'admin', "objects/{$schema}/private_notes{$tab}")->assertOk();
    }

    expect(apiAs($this, 'viewer', "rows/{$schema}/private_notes")->assertNotFound()->getContent())->toBe(NOT_FOUND_BODY)
        ->and(apiAs($this, 'viewer', "schemas/{$schema}/objects")->assertOk()->json('data'))->toBe([])
        ->and(array_column(apiAs($this, 'admin', "schemas/{$schema}/objects")->json('data'), 'name'))->toBe(['private_notes']);
});

it('round-trips object names with dots, spaces, quotes and non-ASCII characters', function (string $name) {
    $schema = $this->scratchSchema();
    $quoted = '"'.str_replace('"', '""', $name).'"';
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$schema}.{$quoted} ({$quoted} int PRIMARY KEY)");

    $path = objectPath($schema, $name);

    expect(apiAs($this, 'viewer', "objects/{$path}")->assertOk()->json('data.name'))->toBe($name)
        ->and(apiAs($this, 'viewer', "objects/{$path}/columns")->assertOk()->json('data.0.name'))->toBe($name)
        ->and(apiAs($this, 'viewer', "objects/{$path}/ddl")->assertOk()->json('data.ddl'))->toContain($quoted);

    // Validation passes with the odd column as sort column; only execution is pending (M1.6).
    apiAs($this, 'viewer', "rows/{$path}?sort=".rawurlencode($name))->assertStatus(501);
})->with([
    'dots' => 'v1.2.table',
    'space' => 'with space',
    'double quote' => 'say "hi"',
    'single quote' => "o'brien",
    'unicode' => 'ünïcødé_日本',
    'emoji' => 'rows 🐘',
    'semicolon' => 'a;b',
    'percent' => '100%',
    'question mark' => 'why?',
    'hash' => 'tag#1',
    'plus' => 'a+b',
    'backslash' => 'back\\slash',
    'upper case' => 'MixedCase',
    'leading dot' => '.hidden',
]);

it('round-trips a function signature with spaces and brackets', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE FUNCTION {$schema}.\"odd fn\"(a character varying, b integer[]) RETURNS int LANGUAGE sql AS 'SELECT 1'");

    expect(apiAs($this, 'viewer', 'functions/'.objectPath($schema, 'odd fn(character varying, integer[])'))->assertOk()->json('data.signature'))
        ->toBe('odd fn(character varying, integer[])');
});

it('answers a client error for a name that is not one UTF-8 path segment', function () {
    apiAs($this, 'viewer', 'objects/fixture/'.rawurlencode('users/columns').'/columns')->assertNotFound();

    // Newer Laravel versions answer a malformed URL with 400 before routing.
    expect(apiAs($this, 'viewer', 'objects/fixture/'.rawurlencode("bad\xC3\x28"))->status())->toBeIn([400, 404]);
});
