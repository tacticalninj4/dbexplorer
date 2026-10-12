<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Events\RowChanged;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Security\Masker;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

beforeEach(function () {
    app()['env'] = 'local';

    $this->schema = $this->scratchSchema();

    DB::connection('pgsql_admin')->unprepared(<<<SQL
        CREATE TYPE {$this->schema}.mood AS ENUM ('sad', 'ok', 'happy');
        CREATE TABLE {$this->schema}.notes (
            id bigserial PRIMARY KEY,
            title text NOT NULL,
            body text,
            password text,
            slug text GENERATED ALWAYS AS (lower(title)) STORED
        );
        CREATE TABLE {$this->schema}.kinds (
            id int PRIMARY KEY,
            meta jsonb,
            doc json,
            tags text[],
            data bytea,
            seen_at timestamptz,
            price numeric(10, 2),
            ref uuid,
            mood {$this->schema}.mood,
            code char(4),
            short varchar(5),
            ratio double precision
        );
        CREATE TABLE {$this->schema}.log (msg text);
        CREATE VIEW {$this->schema}.notes_view AS SELECT id, title FROM {$this->schema}.notes;
        INSERT INTO {$this->schema}.notes (title, body, password) VALUES ('First', 'one', 'hunter2'), ('Second', 'two', 'swordfish');
        INSERT INTO {$this->schema}.log VALUES ('hello');
        SQL);
});

/**
 * Send a row write to /dbexplorer/api/main/rows/{schema}/{table} as $role,
 * with the session's CSRF token and, when $confirmed, a fresh password confirmation.
 *
 * @param  array<string, mixed>  $body
 */
function rowWrite(PgsqlTestCase $test, string $role, string $method, string $table, array $body, bool $confirmed = true, string $query = ''): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    $session = ['_token' => 'csrf-test-token'] + ($confirmed ? ['auth.password_confirmed_at' => time()] : []);

    return $test->actingAs(makeUser())
        ->withSession($session)
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->json($method, '/dbexplorer/api/main/rows/'.rawurlencode($test->schema).'/'.rawurlencode($table).$query, $body);
}

/**
 * @return array<string, mixed>|null
 */
function noteRow(PgsqlTestCase $test, int $id): ?array
{
    $row = DB::connection('pgsql_super')->selectOne("SELECT id, title, body, password FROM {$test->schema}.notes WHERE id = ?", [$id]);

    return $row === null ? null : (array) $row;
}

it('inserts a row and returns it with masked columns masked', function () {
    $row = rowWrite($this, 'editor', 'POST', 'notes', ['values' => ['title' => 'Third', 'body' => null]])
        ->assertCreated()
        ->json('data.row');

    expect($row)->toBe(['id' => 3, 'title' => 'Third', 'body' => null, 'password' => null, 'slug' => 'third'])
        ->and(noteRow($this, 3))->toMatchArray(['title' => 'Third', 'body' => null]);

    $audit = AuditEntry::query()->sole();

    expect($audit->action)->toBe('row.insert')
        ->and($audit->role)->toBe('editor')
        ->and($audit->label)->toBe('main')
        ->and($audit->classification)->toBe('dml')
        ->and($audit->outcome)->toBe('success')
        ->and($audit->row_count)->toBe(1)
        ->and($audit->meta)->toEqual(['table' => "{$this->schema}.notes", 'key' => ['id' => '3']])
        ->and($audit->sql)->toBe("INSERT INTO \"{$this->schema}\".\"notes\" (\"title\", \"body\") VALUES (CAST(? AS text), CAST(? AS text)) RETURNING \"id\", \"title\", \"body\", \"password\", \"slug\"");
});

it('inserts a row of column defaults only when defaults: true is sent', function () {
    DB::connection('pgsql_admin')->statement("ALTER TABLE {$this->schema}.notes ALTER COLUMN title SET DEFAULT 'untitled'");

    expect(rowWrite($this, 'editor', 'POST', 'notes', ['defaults' => true])->assertCreated()->json('data.row.title'))->toBe('untitled')
        ->and(rowWrite($this, 'editor', 'POST', 'notes', ['values' => [], 'defaults' => true, 'preview' => true])->assertOk()->json('data.sql'))
        ->toBe("INSERT INTO \"{$this->schema}\".\"notes\" DEFAULT VALUES RETURNING \"id\", \"title\", \"body\", \"password\", \"slug\"");
});

it('F25d answers 422 for an insert with no values unless defaults: true is sent, and writes nothing', function (array $body) {
    rowWrite($this, 'editor', 'POST', 'notes', $body)
        ->assertStatus(422)
        ->assertJsonPath('errors.values.0', 'Give at least one value, or send defaults: true to insert a row of column defaults.');

    expect(DB::connection('pgsql_super')->selectOne("SELECT count(*) AS n FROM {$this->schema}.notes")->n)->toBe(2)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'no values key' => [[]],
    'empty values' => [['values' => []]],
    'defaults false' => [['values' => [], 'defaults' => false]],
    'preview' => [['values' => [], 'preview' => true]],
]);

it('F25d rejects a defaults flag that is not a boolean', function () {
    rowWrite($this, 'editor', 'POST', 'notes', ['defaults' => 'yes please'])->assertStatus(422)->assertJsonValidationErrors('defaults');
});

it('masks an inserted value in a masked column in the response', function () {
    $row = rowWrite($this, 'admin', 'POST', 'notes', ['values' => ['title' => 'T', 'password' => 'p4ss'], 'unmask' => true])->assertCreated()->json('data.row');
    expect($row['password'])->toBe('p4ss');

    DB::connection('pgsql_super')->update("UPDATE {$this->schema}.notes SET password = 'x' WHERE id = 1");
    $row = rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['title' => 'First'], 'changes' => ['title' => 'Renamed']])->assertOk()->json('data.row');

    expect($row)->toMatchArray(['title' => 'Renamed', 'password' => Masker::MASK]);
});

it('updates a row when the edited columns still hold their original values', function () {
    $row = rowWrite($this, 'editor', 'PATCH', 'notes', [
        'key' => ['id' => 1],
        'original' => ['title' => 'First', 'body' => 'one'],
        'changes' => ['body' => 'uno'],
    ])->assertOk()->json('data.row');

    expect($row)->toBe(['id' => 1, 'title' => 'First', 'body' => 'uno', 'password' => Masker::MASK, 'slug' => 'first'])
        ->and(noteRow($this, 1))->toMatchArray(['body' => 'uno', 'password' => 'hunter2']);

    $audit = AuditEntry::query()->sole();

    expect($audit->action)->toBe('row.update')
        ->and($audit->meta)->toEqual(['table' => "{$this->schema}.notes", 'key' => ['id' => '1']])
        ->and($audit->sql)->toContain('WHERE "id" = CAST(? AS bigint) AND "body"::text IS NOT DISTINCT FROM CAST(? AS text)::text RETURNING');
});

it('deletes a row after a password confirmation, without needing masked originals', function () {
    rowWrite($this, 'editor', 'DELETE', 'notes', ['key' => ['id' => 2], 'original' => ['title' => 'Second', 'body' => 'two', 'slug' => 'second']])
        ->assertOk()
        ->assertExactJson(['data' => ['deleted' => true]]);

    expect(noteRow($this, 2))->toBeNull()->and(noteRow($this, 1))->not->toBeNull();

    $audit = AuditEntry::query()->sole();

    expect($audit->action)->toBe('row.delete')
        ->and($audit->classification)->toBe('destructive')
        ->and($audit->meta)->toEqual(['table' => "{$this->schema}.notes", 'key' => ['id' => '2']]);
});

it('S5 refuses to delete a row without a recent password confirmation', function () {
    rowWrite($this, 'editor', 'DELETE', 'notes', ['key' => ['id' => 2], 'original' => ['title' => 'Second', 'body' => 'two', 'slug' => 'second']], false)
        ->assertStatus(423)
        ->assertExactJson(['message' => 'Password confirmation required.']);

    expect(noteRow($this, 2))->not->toBeNull()->and(AuditEntry::query()->count())->toBe(0);
});

it('answers 409 with the current row when the row changed since it was read', function () {
    // Another session changes the row after the client read it.
    DB::connection('pgsql_super')->update("UPDATE {$this->schema}.notes SET body = 'changed elsewhere' WHERE id = 1");

    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => 'one'], 'changes' => ['body' => 'mine']])
        ->assertStatus(409)
        ->assertExactJson([
            'error' => ['message' => 'The row was changed or deleted since it was read. Nothing was written.'],
            'current' => ['id' => 1, 'title' => 'First', 'body' => 'changed elsewhere', 'password' => Masker::MASK, 'slug' => 'first'],
        ]);

    expect(noteRow($this, 1)['body'])->toBe('changed elsewhere');

    $audit = AuditEntry::query()->sole();
    expect($audit->outcome)->toBe('conflict')->and($audit->row_count)->toBe(0);
});

it('answers 409 when a row to delete changed, and 409 with no current row when it is gone', function () {
    DB::connection('pgsql_super')->update("UPDATE {$this->schema}.notes SET title = 'Moved' WHERE id = 2");

    rowWrite($this, 'editor', 'DELETE', 'notes', ['key' => ['id' => 2], 'original' => ['title' => 'Second', 'body' => 'two', 'slug' => 'second']])
        ->assertStatus(409)
        ->assertJsonPath('current.title', 'Moved');

    expect(noteRow($this, 2))->not->toBeNull();

    DB::connection('pgsql_super')->delete("DELETE FROM {$this->schema}.notes WHERE id = 1");

    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => 'one'], 'changes' => ['body' => 'x']])
        ->assertStatus(409)
        ->assertJsonPath('current', null);
});

it('F25c overwrites after a conflict with the current row as the original, still checked, and audits the overwrite', function () {
    DB::connection('pgsql_super')->update("UPDATE {$this->schema}.notes SET body = 'changed elsewhere' WHERE id = 1");

    $current = rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => 'one'], 'changes' => ['body' => 'mine']])
        ->assertStatus(409)
        ->json('current');

    // Another change lands between the 409 and the overwrite: still a 409.
    DB::connection('pgsql_super')->update("UPDATE {$this->schema}.notes SET body = 'changed again' WHERE id = 1");

    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => $current['body']], 'changes' => ['body' => 'mine'], 'overwrite' => true])
        ->assertStatus(409)
        ->assertJsonPath('current.body', 'changed again');

    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => 'changed again'], 'changes' => ['body' => 'mine'], 'overwrite' => true])
        ->assertOk()
        ->assertJsonPath('data.row.body', 'mine');

    expect(noteRow($this, 1)['body'])->toBe('mine');

    $audits = AuditEntry::query()->orderBy('id')->get();

    expect($audits->pluck('outcome')->all())->toBe(['conflict', 'conflict', 'success'])
        ->and($audits[0]->meta)->toEqual(['table' => "{$this->schema}.notes", 'key' => ['id' => '1']])
        ->and($audits[1]->meta)->toEqual(['table' => "{$this->schema}.notes", 'key' => ['id' => '1'], 'overwrite' => true])
        ->and($audits[2]->action)->toBe('row.update')
        ->and($audits[2]->meta)->toEqual(['table' => "{$this->schema}.notes", 'key' => ['id' => '1'], 'overwrite' => true]);
});

it('F25c only marks updates as overwrites, and rejects an overwrite flag that is not a boolean', function () {
    rowWrite($this, 'editor', 'POST', 'notes', ['values' => ['title' => 'x'], 'overwrite' => true])->assertCreated();

    expect(AuditEntry::query()->sole()->meta)->not->toHaveKey('overwrite');

    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => 'one'], 'changes' => ['body' => 'x'], 'overwrite' => 'yes'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('overwrite');

    expect(noteRow($this, 1)['body'])->toBe('one');
});

it('matches NULL originals', function () {
    DB::connection('pgsql_super')->update("UPDATE {$this->schema}.notes SET body = NULL WHERE id = 1");

    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => null], 'changes' => ['body' => 'filled']])->assertOk();
    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => null], 'changes' => ['body' => 'again']])->assertStatus(409);
});

it('forbids viewers from inserting, updating and deleting rows', function (string $method, array $body) {
    config(['dbexplorer.connections.main.viewer' => 'pgsql_editor']);

    rowWrite($this, 'viewer', $method, 'notes', $body)->assertForbidden();

    expect(DB::connection('pgsql_super')->selectOne("SELECT count(*) AS n, max(body) AS b FROM {$this->schema}.notes"))
        ->n->toBe(2)->b->toBe('two')
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'insert' => ['POST', ['values' => ['title' => 'x']]],
    'update' => ['PATCH', ['key' => ['id' => 1], 'original' => ['body' => 'one'], 'changes' => ['body' => 'x']]],
    'delete' => ['DELETE', ['key' => ['id' => 1], 'original' => []]],
]);

it('answers 422 for tables without a primary key and for views', function (string $table) {
    rowWrite($this, 'admin', 'POST', $table, ['values' => []])
        ->assertStatus(422)
        ->assertJsonPath('errors.table.0', 'Rows can only be edited in tables with a primary key.');

    expect(DB::connection('pgsql_super')->selectOne("SELECT count(*) AS n FROM {$this->schema}.log")->n)->toBe(1);
})->with(['no primary key' => 'log', 'view' => 'notes_view']);

it('S13 answers a hidden table exactly like a missing one', function () {
    config(['dbexplorer.hidden' => ["{$this->schema}.notes"]]);

    $hidden = rowWrite($this, 'admin', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => [], 'changes' => ['body' => 'x']])->assertNotFound()->json();
    $missing = rowWrite($this, 'admin', 'PATCH', 'nope', ['key' => ['id' => 1], 'original' => [], 'changes' => ['body' => 'x']])->assertNotFound()->json();

    expect($hidden)->toBe($missing)->and(noteRow($this, 1)['body'])->toBe('one');
});

it('S12 rejects unknown columns and injection attempts in column names with 422', function (string $method, array $body, string $field) {
    rowWrite($this, 'admin', $method, 'notes', $body)->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect(DB::connection('pgsql_super')->selectOne("SELECT count(*) AS n, string_agg(body, ',' ORDER BY id) AS b FROM {$this->schema}.notes"))
        ->n->toBe(2)->b->toBe('one,two');
})->with([
    'insert unknown column' => ['POST', ['values' => ['nope' => 1]], 'values.nope'],
    'insert injection' => ['POST', ['values' => ['title") VALUES (\'x\'); DROP TABLE notes; --' => 'x']], 'values.title") VALUES (\'x\'); DROP TABLE notes; --'],
    'update injection in changes' => ['PATCH', ['key' => ['id' => 1], 'original' => [], 'changes' => ['body" = \'x\' --' => 'y']], 'changes.body" = \'x\' --'],
    'update case mismatch' => ['PATCH', ['key' => ['id' => 1], 'original' => [], 'changes' => ['BODY' => 'y']], 'changes.BODY'],
    'update injection in key' => ['PATCH', ['key' => ['id = id OR true --' => 1], 'original' => [], 'changes' => ['body' => 'y']], 'key.id = id OR true --'],
    'update injection in original' => ['PATCH', ['key' => ['id' => 1], 'original' => ['body" IS NOT NULL OR "1' => 'one'], 'changes' => ['body' => 'y']], 'original.body" IS NOT NULL OR "1'],
    'key missing a column' => ['PATCH', ['key' => [], 'original' => ['body' => 'one'], 'changes' => ['body' => 'y']], 'key.id'],
    'key with a NULL value' => ['PATCH', ['key' => ['id' => null], 'original' => ['body' => 'one'], 'changes' => ['body' => 'y']], 'key.id'],
    'key with a non-key column' => ['DELETE', ['key' => ['id' => 1, 'title' => 'First'], 'original' => []], 'key.title'],
    'key not an object' => ['PATCH', ['key' => 'id=1', 'original' => [], 'changes' => ['body' => 'y']], 'key'],
    'value not a scalar' => ['PATCH', ['key' => ['id' => 1], 'original' => ['body' => 'one'], 'changes' => ['body' => ['a' => 1]]], 'changes.body'],
    'no changes' => ['PATCH', ['key' => ['id' => 1], 'original' => [], 'changes' => []], 'changes'],
    'missing original for an edited column' => ['PATCH', ['key' => ['id' => 1], 'original' => [], 'changes' => ['body' => 'y']], 'original.body'],
    'missing original for delete' => ['DELETE', ['key' => ['id' => 1], 'original' => ['title' => 'First']], 'original.body'],
    'generated column' => ['PATCH', ['key' => ['id' => 1], 'original' => [], 'changes' => ['slug' => 'y']], 'changes.slug'],
]);

it('refuses to write GENERATED ALWAYS identity columns', function () {
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$this->schema}.ids (id int GENERATED ALWAYS AS IDENTITY PRIMARY KEY, name text)");

    rowWrite($this, 'admin', 'POST', 'ids', ['values' => ['id' => 5, 'name' => 'x']])->assertStatus(422)->assertJsonValidationErrors(['values.id']);
    expect(rowWrite($this, 'admin', 'POST', 'ids', ['values' => ['name' => 'x']])->assertCreated()->json('data.row'))->toBe(['id' => 1, 'name' => 'x']);
});

it('S14 refuses to write a masked column unless an admin unmasks', function (string $role, bool $unmask, int $status) {
    rowWrite($this, $role, 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => [], 'changes' => ['password' => 'new'], 'unmask' => $unmask])
        ->assertStatus($status);

    expect(noteRow($this, 1)['password'])->toBe($status === 200 ? 'new' : 'hunter2');
})->with([
    'editor' => ['editor', false, 422],
    'admin without unmask' => ['admin', false, 422],
    'editor asking to unmask' => ['editor', true, 403],
    'admin unmasking' => ['admin', true, 200],
]);

it('S14 names the masked column, and audits an admin unmask', function () {
    rowWrite($this, 'editor', 'POST', 'notes', ['values' => ['title' => 'x', 'password' => 'p']])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['values.password' => ['The column is masked, so only an admin who unmasks can write it.']]);

    $row = rowWrite($this, 'admin', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['password' => 'hunter2'], 'changes' => ['password' => 'new']], true, '?unmask=1')
        ->assertOk()
        ->json('data.row');

    expect($row['password'])->toBe('new')
        ->and(AuditEntry::query()->sole()->meta)->toEqual(['table' => "{$this->schema}.notes", 'key' => ['id' => '1'], 'unmasked' => true]);
});

it('S14 makes a table with a masked primary key read-only unless an admin unmasks', function () {
    DB::connection('pgsql_admin')->unprepared("CREATE TABLE {$this->schema}.tokens (api_token text PRIMARY KEY, note text); INSERT INTO {$this->schema}.tokens VALUES ('t0k', 'n')");

    rowWrite($this, 'admin', 'PATCH', 'tokens', ['key' => ['api_token' => 't0k'], 'original' => ['note' => 'n'], 'changes' => ['note' => 'm']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['table']);

    rowWrite($this, 'admin', 'PATCH', 'tokens', ['key' => ['api_token' => 't0k'], 'original' => ['note' => 'n'], 'changes' => ['note' => 'm'], 'unmask' => true])->assertOk();
});

it('previews the SQL with literals and runs nothing', function (string $method, array $body) {
    $sql = rowWrite($this, 'editor', $method, 'notes', $body + ['preview' => true], false)->assertOk()->json('data.sql');

    expect($sql)->toContain("'O''Brien'")
        ->and(DB::connection('pgsql_super')->selectOne("SELECT count(*) AS n, string_agg(title, ',' ORDER BY id) AS t FROM {$this->schema}.notes"))
        ->n->toBe(2)->t->toBe('First,Second')
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'insert' => ['POST', ['values' => ['title' => "O'Brien"]]],
    'update' => ['PATCH', ['key' => ['id' => 1], 'original' => ['title' => 'First'], 'changes' => ['title' => "O'Brien"]]],
    'delete' => ['DELETE', ['key' => ['id' => 1], 'original' => ['title' => "O'Brien", 'body' => null, 'slug' => 'first']]],
]);

it('renders the full preview statement', function () {
    $sql = rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => null], 'changes' => ['body' => "it's"]], true, '?preview=1')
        ->assertOk()
        ->json('data.sql');

    $table = "\"{$this->schema}\".\"notes\"";

    expect($sql)->toBe("UPDATE {$table} SET \"body\" = CAST('it''s' AS text) WHERE \"id\" = CAST('1' AS bigint) AND \"body\"::text IS NOT DISTINCT FROM CAST(NULL AS text)::text RETURNING \"id\", \"title\", \"body\", \"password\", \"slug\"");
});

it('round-trips jsonb, json, arrays, bytea, timestamptz, numeric, uuid, enums, char, floats and NULLs', function () {
    $values = [
        'id' => 1,
        'meta' => '{"a": [1, 2], "b": {"c": null}}',
        'doc' => '{"z":1,  "a":2}',
        'tags' => '{one,"two words",NULL}',
        'data' => '\\xdeadbeef00',
        'seen_at' => '2026-01-02 03:04:05.123456+00',
        'price' => '12.5',
        'ref' => 'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11',
        'mood' => 'happy',
        'code' => 'ab',
        'short' => 'abc',
        'ratio' => 0.1,
    ];

    $row = rowWrite($this, 'editor', 'POST', 'kinds', ['values' => $values])->assertCreated()->json('data.row');

    expect($row)->toMatchArray([
        'id' => 1,
        'meta' => '{"a": [1, 2], "b": {"c": null}}',
        'doc' => '{"z":1,  "a":2}',
        'tags' => '{one,"two words",NULL}',
        'data' => '\\xdeadbeef00',
        'price' => '12.50',
        'ref' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11',
        'mood' => 'happy',
        'code' => 'ab  ',
        'short' => 'abc',
    ])->and((float) $row['ratio'])->toBe(0.1);

    expect(DB::connection('pgsql_super')->selectOne("SELECT seen_at = '2026-01-02 03:04:05.123456+00' AS same, encode(data, 'hex') AS hex, meta->'a'->>1 AS a1 FROM {$this->schema}.kinds"))
        ->same->toBeTrue()->hex->toBe('deadbeef00')->a1->toBe('2');

    // The row as returned is a valid original for every column, including json, which has no = operator.
    $original = array_diff_key($row, ['id' => true]);
    $updated = rowWrite($this, 'editor', 'PATCH', 'kinds', ['key' => ['id' => 1], 'original' => $original, 'changes' => [
        'meta' => null, 'doc' => null, 'tags' => null, 'data' => null, 'seen_at' => null, 'price' => null,
        'ref' => null, 'mood' => null, 'code' => null, 'short' => null, 'ratio' => null,
    ]])->assertOk()->json('data.row');

    expect(array_filter($updated, fn (mixed $value): bool => $value !== null))->toBe(['id' => 1]);

    $nulls = array_diff_key($updated, ['id' => true]);
    rowWrite($this, 'editor', 'DELETE', 'kinds', ['key' => ['id' => '1'], 'original' => $nulls])->assertOk();

    expect(DB::connection('pgsql_super')->selectOne("SELECT count(*) AS n FROM {$this->schema}.kinds")->n)->toBe(0);
});

it('checks every column of a row to delete, including json and bytea', function (string $column, mixed $stale) {
    DB::connection('pgsql_super')->insert("INSERT INTO {$this->schema}.kinds (id, doc, data, tags) VALUES (1, '{\"a\":1}', '\\xff', '{x}')");

    $original = ['meta' => null, 'doc' => '{"a":1}', 'tags' => '{x}', 'data' => '\\xff', 'seen_at' => null, 'price' => null,
        'ref' => null, 'mood' => null, 'code' => null, 'short' => null, 'ratio' => null];

    rowWrite($this, 'editor', 'DELETE', 'kinds', ['key' => ['id' => 1], 'original' => [$column => $stale] + $original])->assertStatus(409);
    rowWrite($this, 'editor', 'DELETE', 'kinds', ['key' => ['id' => 1], 'original' => $original])->assertOk();
})->with([
    'json' => ['doc', '{"a":2}'],
    'bytea' => ['data', '\\xfe'],
    'array' => ['tags', '{y}'],
    'NULL vs value' => ['meta', '{}'],
]);

it('rejects bytea values that are not hex', function (string $value) {
    rowWrite($this, 'editor', 'POST', 'kinds', ['values' => ['id' => 1, 'data' => $value]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['values.data']);
})->with(['plain text' => 'hello', 'odd digits' => '\\xabc', 'escape format' => '\\001']);

it('reports database errors as 422 without silently truncating or coercing values', function (string $column, mixed $value, string $state) {
    rowWrite($this, 'editor', 'POST', 'kinds', ['values' => ['id' => 1, $column => $value]])
        ->assertStatus(422)
        ->assertJsonPath('error.sqlstate', $state);

    $audit = AuditEntry::query()->sole();

    expect($audit->outcome)->toBe('error')
        ->and($audit->error)->toStartWith($state)
        ->and($audit->meta['sqlstate'])->toBe($state)
        ->and(DB::connection('pgsql_super')->selectOne("SELECT count(*) AS n FROM {$this->schema}.kinds")->n)->toBe(0);
})->with([
    'varchar too long' => ['short', 'abcdefgh', '22001'],
    'char too long' => ['code', 'abcde', '22001'],
    'bad enum' => ['mood', 'angry', '22P02'],
    'bad uuid' => ['ref', 'nope', '22P02'],
    'numeric overflow' => ['price', '123456789012', '22003'],
]);

it('keeps column values out of the audit log and the RowChanged event', function () {
    Event::fake([RowChanged::class]);

    rowWrite($this, 'editor', 'POST', 'notes', ['values' => ['title' => 'TopSecretTitle', 'body' => 'PrivateBody']])->assertCreated();
    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => 'one'], 'changes' => ['body' => 'PrivateEdit']])->assertOk();
    rowWrite($this, 'editor', 'DELETE', 'notes', ['key' => ['id' => 2], 'original' => ['title' => 'Second', 'body' => 'two', 'slug' => 'second']])->assertOk();

    $audit = json_encode(AuditEntry::query()->orderBy('id')->get()->toArray());

    foreach (['TopSecretTitle', 'PrivateBody', 'PrivateEdit', 'one', 'Second', 'hunter2', 'swordfish', 'pgsql_editor', 'dbx-test-password'] as $value) {
        expect($audit)->not->toContain($value);
    }

    $events = [];

    Event::assertDispatched(RowChanged::class, function (RowChanged $event) use (&$events): bool {
        $events[] = $event;

        return true;
    });

    expect(array_map(fn (RowChanged $event): array => [$event->operation, $event->key, $event->schema, $event->table, $event->role, $event->label, $event->userId], $events))
        ->toBe([
            ['insert', ['id' => '3'], $this->schema, 'notes', 'editor', 'main', '1'],
            ['update', ['id' => '1'], $this->schema, 'notes', 'editor', 'main', '1'],
            ['delete', ['id' => '2'], $this->schema, 'notes', 'editor', 'main', '1'],
        ])
        ->and(array_map(fn (RowChanged $event): int => $event->auditId, $events))
        ->toBe(AuditEntry::query()->orderBy('id')->pluck('id')->all());

    $payload = serialize($events);

    foreach (['TopSecretTitle', 'PrivateBody', 'PrivateEdit', 'hunter2', 'pgsql_editor', 'dbx-test-password', '127.0.0.1'] as $value) {
        expect($payload)->not->toContain($value);
    }
});

it('fires no RowChanged event for a conflict, an error or a preview', function () {
    Event::fake([RowChanged::class]);

    DB::connection('pgsql_super')->update("UPDATE {$this->schema}.notes SET body = 'x' WHERE id = 1");
    rowWrite($this, 'editor', 'PATCH', 'notes', ['key' => ['id' => 1], 'original' => ['body' => 'one'], 'changes' => ['body' => 'y']])->assertStatus(409);
    rowWrite($this, 'editor', 'POST', 'kinds', ['values' => ['id' => 1, 'short' => 'too long here']])->assertStatus(422);
    rowWrite($this, 'editor', 'POST', 'notes', ['values' => ['title' => 'x'], 'preview' => true])->assertOk();

    Event::assertNotDispatched(RowChanged::class);
});

it('runs each write as the role login, which the database can refuse', function () {
    DB::connection('pgsql_admin')->statement("REVOKE DELETE ON {$this->schema}.notes FROM dbx_editor");

    rowWrite($this, 'editor', 'DELETE', 'notes', ['key' => ['id' => 2], 'original' => ['title' => 'Second', 'body' => 'two', 'slug' => 'second']])
        ->assertStatus(422)
        ->assertJsonPath('error.sqlstate', '42501');

    expect(noteRow($this, 2))->not->toBeNull();
});

it('atomic() commits, rolls back on an exception and resets the session', function () {
    $runner = app(QueryRunner::class);

    $runner->atomic('pgsql_editor', 'editor', function (Closure $statement): void {
        $statement("UPDATE {$this->schema}.notes SET body = ? WHERE id = ?", ['kept', 1]);
        $statement("SELECT set_config('application_name', 'leaked', false)", []);
    });

    try {
        $runner->atomic('pgsql_editor', 'editor', function (Closure $statement): void {
            $statement("UPDATE {$this->schema}.notes SET body = ? WHERE id = ?", ['undone', 2]);

            throw new RuntimeException('stop');
        });
    } catch (RuntimeException) {
        //
    }

    $failure = null;

    try {
        $runner->atomic('pgsql_editor', 'editor', fn (Closure $statement): array => $statement('SELECT 1; SELECT 2', []));
    } catch (QueryFailed $e) {
        $failure = $e;
    }

    expect(noteRow($this, 1)['body'])->toBe('kept')
        ->and(noteRow($this, 2)['body'])->toBe('two')
        ->and($failure?->sqlState)->toBe('42601')
        ->and(DB::connection('pgsql_editor')->selectOne('SHOW application_name')->application_name)->not->toBe('leaked')
        ->and(DB::connection('pgsql_editor')->getPdo()->inTransaction())->toBeFalse();
});

it('atomic() refuses a connection with an open transaction and applies the role timeout', function () {
    $runner = app(QueryRunner::class);

    DB::connection('pgsql_editor')->beginTransaction();

    expect(fn () => $runner->atomic('pgsql_editor', 'editor', fn (): int => 1))
        ->toThrow(QueryFailed::class, 'The connection already has an open transaction.');

    DB::connection('pgsql_editor')->rollBack();
    config(['dbexplorer.limits.editor.timeout' => 1]);

    $failure = null;

    try {
        $runner->atomic('pgsql_editor', 'editor', fn (Closure $statement): array => $statement('SELECT pg_sleep(30)', []));
    } catch (QueryFailed $e) {
        $failure = $e;
    }

    expect($failure?->sqlState)->toBe('57014');
});
