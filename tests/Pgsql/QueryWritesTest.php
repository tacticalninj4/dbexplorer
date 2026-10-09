<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Events\DdlExecuted;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;
use Workbench\App\Models\User;

// M2.2: DML, DDL and destructive SQL through POST api/{label}/query.

const WRITER_PASSWORD = 'correct-horse-battery-staple';

function writer(): User
{
    $user = makeUser();
    $user->forceFill(['password' => Hash::make(WRITER_PASSWORD)]);

    return $user;
}

/**
 * POST $uri as a user with a password and $role on `main`, with the session's
 * CSRF token, as the SPA sends it.
 *
 * @param  array<string, mixed>  $body
 */
function postAsRole(PgsqlTestCase $test, string $role, string $uri, array $body = []): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(writer())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson($uri, $body);
}

/**
 * Run $sql through the query API as $role, confirmed (`confirm: true`) unless $confirm is false.
 */
function writeQuery(PgsqlTestCase $test, string $role, string $sql, bool $confirm = true): TestResponse
{
    return postAsRole($test, $role, '/dbexplorer/api/main/query', ['sql' => $sql] + ($confirm ? ['confirm' => true] : []));
}

beforeEach(function () {
    app()['env'] = 'local';
    config(['hashing.bcrypt.rounds' => 4]);

    $this->schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$this->schema}.notes (id bigserial PRIMARY KEY, body text)");
    DB::connection('pgsql_admin')->insert("INSERT INTO {$this->schema}.notes (body) VALUES ('one'), ('two'), ('three')");
});

function notesLeft(string $schema): int
{
    return (int) DB::connection('pgsql_super')->table("{$schema}.notes")->count();
}

function tableExists(string $name): bool
{
    return DB::connection('pgsql_super')->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', [$name])->present;
}

it('lets an editor run DML without a password confirmation', function () {
    writeQuery($this, 'editor', "UPDATE {$this->schema}.notes SET body = 'uno' WHERE id = 1")
        ->assertOk()
        ->assertJsonPath('data.classification', 'dml')
        ->assertJsonPath('data.affected_rows', 1);

    expect(DB::connection('pgsql_super')->table("{$this->schema}.notes")->where('id', 1)->value('body'))->toBe('uno');
});

it('S5 answers destructive SQL with 423 until the password is confirmed, then runs it', function () {
    writeQuery($this, 'admin', "DROP TABLE {$this->schema}.notes")
        ->assertStatus(423)
        ->assertExactJson(['message' => 'Password confirmation required.']);

    expect(tableExists("{$this->schema}.notes"))->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'query')->count())->toBe(0);

    $this->postJson('/dbexplorer/api/confirm-password', ['password' => WRITER_PASSWORD])->assertNoContent();

    $this->postJson('/dbexplorer/api/main/query', ['sql' => "DROP TABLE {$this->schema}.notes", 'confirm' => true])
        ->assertOk()
        ->assertJsonPath('data.classification', 'destructive');

    expect(tableExists("{$this->schema}.notes"))->toBeFalse();
});

it('S5 asks an editor to confirm a DELETE or UPDATE without WHERE', function (string $sql, int $left) {
    writeQuery($this, 'editor', str_replace('{schema}', $this->schema, $sql))->assertStatus(423);

    expect(notesLeft($this->schema))->toBe(3);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/dbexplorer/api/main/query', ['sql' => str_replace('{schema}', $this->schema, $sql), 'confirm' => true])
        ->assertOk()
        ->assertJsonPath('data.classification', 'destructive');

    expect(notesLeft($this->schema))->toBe($left);
})->with([
    'delete without where' => ['DELETE FROM {schema}.notes', 0],
    'update without where' => ["UPDATE {schema}.notes SET body = 'x'", 3],
    'delete in a CTE' => ['WITH d AS (DELETE FROM {schema}.notes RETURNING id) SELECT count(*) FROM d', 0],
]);

it('S5 asks a viewer to confirm destructive SQL too, and the read-only transaction still refuses it', function () {
    config(['dbexplorer.connections.main.viewer' => 'pgsql_editor']);

    writeQuery($this, 'viewer', "DELETE FROM {$this->schema}.notes")->assertStatus(423);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/dbexplorer/api/main/query', ['sql' => "DELETE FROM {$this->schema}.notes", 'confirm' => true])
        ->assertStatus(422)
        ->assertJsonPath('error.sqlstate', '25006');

    expect(notesLeft($this->schema))->toBe(3);
});

it('S5 skips the confirmation while dbexplorer.confirm_password is false', function () {
    config(['dbexplorer.confirm_password' => false]);

    writeQuery($this, 'editor', "DELETE FROM {$this->schema}.notes")->assertOk();

    expect(notesLeft($this->schema))->toBe(0);
});

it('asks to confirm anything the classifier does not call a read with 409, running nothing', function (string $role, string $sql, string $class) {
    writeQuery($this, $role, str_replace('{schema}', $this->schema, $sql), false)
        ->assertStatus(409)
        ->assertExactJson(['error' => [
            'message' => "This statement looks like {$class} SQL. Confirm to run it.",
            'code' => 'confirmation_required',
            'classification' => $class,
        ]]);

    expect(notesLeft($this->schema))->toBe(3)
        ->and(DB::connection('pgsql_super')->table("{$this->schema}.notes")->where('body', 'x')->count())->toBe(0)
        ->and(AuditEntry::query()->where('action', 'query')->count())->toBe(0);
})->with([
    'editor dml' => ['editor', "UPDATE {schema}.notes SET body = 'x' WHERE id = 1", 'dml'],
    'editor destructive' => ['editor', 'DELETE FROM {schema}.notes', 'destructive'],
    'editor unknown' => ['editor', 'LOCK TABLE {schema}.notes', 'unknown'],
    'admin ddl' => ['admin', 'CREATE TABLE {schema}.made (id int)', 'ddl'],
    'admin destructive' => ['admin', 'DROP TABLE {schema}.notes', 'destructive'],
    'viewer dml' => ['viewer', "INSERT INTO {schema}.notes (body) VALUES ('x')", 'dml'],
]);

it('runs reads without asking to confirm', function (string $role) {
    writeQuery($this, $role, "SELECT count(*) FROM {$this->schema}.notes", false)
        ->assertOk()
        ->assertJsonPath('data.rows', [[3]]);
})->with(['viewer', 'editor', 'admin']);

it('asks to confirm before asking for the password, and forbids DDL before either', function () {
    writeQuery($this, 'editor', "DELETE FROM {$this->schema}.notes", false)->assertStatus(409);
    writeQuery($this, 'editor', "DELETE FROM {$this->schema}.notes")->assertStatus(423);
    writeQuery($this, 'editor', "CREATE TABLE {$this->schema}.made (id int)", false)->assertForbidden();

    expect(notesLeft($this->schema))->toBe(3);
});

it('does not ask to confirm a destructive statement that is only explained', function () {
    writeQuery($this, 'editor', "DELETE FROM {$this->schema}.notes")->assertStatus(423);

    $this->postJson('/dbexplorer/api/main/query', ['sql' => "DELETE FROM {$this->schema}.notes", 'explain' => 'analyze'])
        ->assertOk()
        ->assertJsonPath('data.explain', 'analyze');

    expect(notesLeft($this->schema))->toBe(3);
});

it('answers DDL from viewers and editors with 403 before it reaches the database', function (string $role) {
    writeQuery($this, $role, "CREATE TABLE {$this->schema}.made (id int)")
        ->assertForbidden()
        ->assertJsonPath('message', 'Only admins can run DDL (CREATE, ALTER, DROP, TRUNCATE, GRANT, ...).');

    expect(tableExists("{$this->schema}.made"))->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'query')->count())->toBe(0);
})->with(['viewer', 'editor']);

it('answers destructive DDL from viewers and editors with 403 before asking to confirm', function (string $role, string $sql) {
    writeQuery($this, $role, str_replace('{schema}', $this->schema, $sql), false)
        ->assertForbidden()
        ->assertJsonPath('message', 'Only admins can run DDL (CREATE, ALTER, DROP, TRUNCATE, GRANT, ...).');

    expect(tableExists("{$this->schema}.notes"))->toBeTrue()
        ->and(notesLeft($this->schema))->toBe(3)
        ->and(AuditEntry::query()->where('action', 'query')->count())->toBe(0);
})->with([
    'editor DROP' => ['editor', 'DROP TABLE {schema}.notes'],
    'editor TRUNCATE' => ['editor', 'TRUNCATE {schema}.notes'],
    'editor ALTER ... DROP' => ['editor', 'ALTER TABLE {schema}.notes DROP COLUMN body'],
    'viewer DROP' => ['viewer', 'DROP TABLE {schema}.notes'],
]);

it('lets an editor run SQL the classifier cannot place, leaving the database to decide', function () {
    writeQuery($this, 'editor', "LOCK TABLE {$this->schema}.notes IN SHARE MODE")
        ->assertOk()
        ->assertJsonPath('data.classification', 'unknown');
});

it('runs DDL for an admin and fires DdlExecuted after the audit row, without bindings or config', function () {
    Event::fake([DdlExecuted::class]);

    $data = writeQuery($this, 'admin', "CREATE TABLE {$this->schema}.made (id int)")
        ->assertOk()
        ->assertJsonPath('data.classification', 'ddl')
        ->json('data');

    expect(tableExists("{$this->schema}.made"))->toBeTrue()
        ->and($data)->toHaveKey('duration_ms');

    $events = Event::dispatched(DdlExecuted::class)->map(fn (array $args): DdlExecuted => $args[0]);
    $event = $events->first();
    $audit = AuditEntry::query()->where('action', 'query')->sole();
    $payload = (string) json_encode(get_object_vars($event));
    $database = config('database.connections.pgsql_admin');

    expect($events)->toHaveCount(1)
        ->and(get_object_vars($event))->toBe([
            'auditId' => $audit->id,
            'userId' => '1',
            'label' => 'main',
            'role' => 'admin',
            'classification' => 'ddl',
            'sql' => "CREATE TABLE {$this->schema}.made (id int)",
        ])
        ->and($payload)->not->toContain($database['password'])
        ->and($payload)->not->toContain('dbx_admin')
        ->and($payload)->not->toContain('pgsql_admin')
        ->and($payload)->not->toContain('$2y$');
});

it('fires DdlExecuted for committed destructive DDL, but not for reads, DML, destructive DML, failures or explains', function () {
    Event::fake([DdlExecuted::class]);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    writeQuery($this, 'admin', "SELECT * FROM {$this->schema}.notes")->assertOk();
    writeQuery($this, 'admin', "UPDATE {$this->schema}.notes SET body = 'x' WHERE id = 1")->assertOk();
    writeQuery($this, 'admin', 'CREATE TABLE no_such_schema.made (id int)')->assertStatus(422);
    $this->postJson('/dbexplorer/api/main/query', ['sql' => "DELETE FROM {$this->schema}.notes", 'explain' => 'analyze'])->assertOk();
    writeQuery($this, 'admin', "UPDATE {$this->schema}.notes SET body = 'y'")->assertOk()->assertJsonPath('data.classification', 'destructive');
    writeQuery($this, 'admin', "WITH d AS (DELETE FROM {$this->schema}.notes WHERE id = 1 RETURNING id) DELETE FROM {$this->schema}.notes")->assertOk();

    Event::assertNotDispatched(DdlExecuted::class);

    $this->postJson('/dbexplorer/api/main/query', ['sql' => "TRUNCATE {$this->schema}.notes", 'confirm' => true])->assertOk();

    Event::assertDispatched(DdlExecuted::class, fn (DdlExecuted $event): bool => $event->classification === 'destructive'
        && $event->sql === "TRUNCATE {$this->schema}.notes");
    Event::assertDispatchedTimes(DdlExecuted::class, 1);
});
