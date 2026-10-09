<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Events\DdlExecuted;
use TacticalNinj4\DbExplorer\Events\QueryExecuted;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\QueryHistory;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

// M2.4: POST api/{label}/ddl/preview and ddl/execute.

/**
 * POST `{op: $op}` to /dbexplorer/api/main/ddl/{$action} as $role, with the
 * session's CSRF token and, unless $confirmed is false, a fresh password confirmation.
 *
 * @param  array<string, mixed>  $op
 */
function ddlAs(PgsqlTestCase $test, string $role, string $action, array $op, bool $confirmed = true): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'] + ($confirmed ? ['auth.password_confirmed_at' => time()] : []))
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson("/dbexplorer/api/main/ddl/{$action}", ['op' => $op]);
}

function relationExists(string $name): bool
{
    return DB::connection('pgsql_super')->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', [$name])->present;
}

beforeEach(function () {
    app()['env'] = 'local';

    $this->schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$this->schema}.notes (id bigserial PRIMARY KEY, body text)");

    $this->createWidgets = [
        'op' => 'create_table',
        'schema' => $this->schema,
        'name' => 'widgets',
        'columns' => [
            ['name' => 'id', 'type' => 'bigserial', 'primary' => true],
            ['name' => 'label', 'type' => 'varchar(80)', 'nullable' => false, 'comment' => 'Shown to users'],
        ],
    ];
});

it('previews the statements and a migration snippet without running anything', function () {
    $data = ddlAs($this, 'admin', 'preview', $this->createWidgets)->assertOk()->json('data');

    expect($data['statements'])->toBe([
        [
            'sql' => "CREATE TABLE \"{$this->schema}\".\"widgets\" (\n    \"id\" bigserial PRIMARY KEY,\n    \"label\" character varying(80) NOT NULL\n)",
            'classification' => 'ddl',
            'transactional' => true,
            'raw_expressions' => false,
        ],
        [
            'sql' => "COMMENT ON COLUMN \"{$this->schema}\".\"widgets\".\"label\" IS 'Shown to users'",
            'classification' => 'ddl',
            'transactional' => true,
            'raw_expressions' => false,
        ],
    ])
        ->and($data['migration'])->toBeString()->toContain('Schema::create(')
        ->and(relationExists("{$this->schema}.widgets"))->toBeFalse()
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('returns a null migration for operations Blueprint cannot express', function () {
    ddlAs($this, 'admin', 'preview', ['op' => 'drop_table', 'schema' => $this->schema, 'name' => 'notes'])
        ->assertOk()
        ->assertJsonPath('data.migration', null)
        ->assertJsonPath('data.statements.0.classification', 'destructive');
});

it('forbids viewers and editors from previewing or executing', function (string $role, string $action) {
    ddlAs($this, $role, $action, $this->createWidgets)
        ->assertForbidden()
        ->assertJsonPath('message', 'Only admins can change the schema.');

    expect(relationExists("{$this->schema}.widgets"))->toBeFalse();
})->with([
    'viewer preview' => ['viewer', 'preview'],
    'viewer execute' => ['viewer', 'execute'],
    'editor preview' => ['editor', 'preview'],
    'editor execute' => ['editor', 'execute'],
]);

it('answers an invalid operation with 422 and the field at fault', function (array $op, string $field) {
    ddlAs($this, 'admin', 'execute', $op + ['schema' => $this->schema])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(AuditEntry::query()->count())->toBe(0);
})->with([
    'unknown op' => [['op' => 'explode_table', 'name' => 'notes'], 'op'],
    'injected type' => [['op' => 'add_column', 'table' => 'notes', 'column' => ['name' => 'x', 'type' => 'int; DROP TABLE x']], 'column.type'],
    'unknown type' => [['op' => 'add_column', 'table' => 'notes', 'column' => ['name' => 'x', 'type' => 'no_such_type']], 'column.type'],
    'no columns' => [['op' => 'create_table', 'name' => 'empty', 'columns' => []], 'columns'],
]);

it('requires op to be an object', function (mixed $op) {
    DbExplorer::roleUsing(fn (): string => 'admin');

    $this->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/ddl/preview', $op === null ? [] : ['op' => $op])
        ->assertStatus(422)
        ->assertJsonValidationErrors('op');
})->with(['missing' => [null], 'a string' => ['create_table']]);

it('executes every statement in one transaction, audits it as one ddl entry and fires DdlExecuted', function () {
    Event::fake([DdlExecuted::class, QueryExecuted::class]);

    $data = ddlAs($this, 'admin', 'execute', $this->createWidgets)->assertOk()->json('data');

    expect($data['statements'])->toHaveCount(2)
        ->and($data['duration_ms'])->toBeNumeric()
        ->and(relationExists("{$this->schema}.widgets"))->toBeTrue()
        ->and(DB::connection('pgsql_super')->selectOne("SELECT col_description('{$this->schema}.widgets'::regclass, 2) AS c")->c)->toBe('Shown to users');

    $audit = AuditEntry::query()->sole();
    $sql = implode(";\n", array_column($data['statements'], 'sql'));

    expect($audit)
        ->action->toBe('ddl')
        ->role->toBe('admin')
        ->label->toBe('main')
        ->user_id->toBe('1')
        ->classification->toBe('ddl')
        ->outcome->toBe('success')
        ->sql->toBe($sql)
        ->and($audit->meta)->toBe(['statements' => 2])
        ->and(QueryHistory::query()->count())->toBe(0);

    Event::assertNotDispatched(QueryExecuted::class);
    Event::assertDispatchedTimes(DdlExecuted::class, 1);

    $event = Event::dispatched(DdlExecuted::class)->first()[0];
    $payload = (string) json_encode(get_object_vars($event));

    expect(get_object_vars($event))->toBe([
        'auditId' => $audit->id,
        'userId' => '1',
        'label' => 'main',
        'role' => 'admin',
        'classification' => 'ddl',
        'sql' => $sql,
    ])
        ->and($payload)->not->toContain((string) config('database.connections.pgsql_admin.password'))
        ->and($payload)->not->toContain('pgsql_admin');
});

it('S5 answers a destructive operation with 423 until the password is confirmed', function () {
    $drop = ['op' => 'drop_table', 'schema' => $this->schema, 'name' => 'notes'];

    ddlAs($this, 'admin', 'execute', $drop, false)
        ->assertStatus(423)
        ->assertExactJson(['message' => 'Password confirmation required.']);

    expect(relationExists("{$this->schema}.notes"))->toBeTrue();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/dbexplorer/api/main/ddl/execute', ['op' => $drop])
        ->assertOk()
        ->assertJsonPath('data.statements.0.classification', 'destructive');

    expect(relationExists("{$this->schema}.notes"))->toBeFalse();
});

it('does not ask to confirm a non-destructive operation', function () {
    ddlAs($this, 'admin', 'execute', ['op' => 'rename_table', 'schema' => $this->schema, 'name' => 'notes', 'to' => 'memos'], false)
        ->assertOk();

    expect(relationExists("{$this->schema}.memos"))->toBeTrue();
});

it('answers CONCURRENTLY with 409 until background jobs exist, without running or confirming', function (array $op) {
    ddlAs($this, 'admin', 'execute', $op + ['schema' => $this->schema], false)
        ->assertStatus(409)
        ->assertJsonPath('message', 'This operation cannot run in a transaction and needs a background job, which is not available yet.');

    expect(AuditEntry::query()->count())->toBe(0)
        ->and(relationExists("{$this->schema}.notes_body_idx"))->toBeFalse();
})->with([
    'create index' => [['op' => 'create_index', 'table' => 'notes', 'name' => 'notes_body_idx', 'columns' => ['body'], 'concurrently' => true]],
    'drop index' => [['op' => 'drop_index', 'name' => 'notes_pkey', 'concurrently' => true]],
]);

it('rolls back the first statement when the second fails, and reports which one failed', function () {
    // Fail every COMMENT in this database, so the CREATE TABLE before it must roll back.
    $trigger = 'dbx_no_comments_'.substr($this->schema, 2);
    DB::connection('pgsql_super')->unprepared(<<<SQL
        CREATE FUNCTION {$this->schema}.no_comments() RETURNS event_trigger LANGUAGE plpgsql AS \$\$
        BEGIN
            RAISE EXCEPTION 'comments are frozen' USING ERRCODE = 'P0001';
        END
        \$\$;
        CREATE EVENT TRIGGER {$trigger} ON ddl_command_end WHEN TAG IN ('COMMENT') EXECUTE FUNCTION {$this->schema}.no_comments();
        SQL);

    try {
        $error = ddlAs($this, 'admin', 'execute', $this->createWidgets)
            ->assertStatus(422)
            ->assertJsonPath('error.sqlstate', 'P0001')
            ->assertJsonPath('error.statement', 1)
            ->json('error');
    } finally {
        DB::connection('pgsql_super')->statement("DROP EVENT TRIGGER IF EXISTS {$trigger}");
    }

    $audit = AuditEntry::query()->sole();

    expect($error['message'])->toContain('comments are frozen')
        ->and(relationExists("{$this->schema}.widgets"))->toBeFalse()
        ->and($audit->outcome)->toBe('error')
        ->and($audit->meta)->toMatchArray(['statements' => 2, 'failed_statement' => 1, 'sqlstate' => 'P0001']);
});

it('runs statement lists in one transaction with the role timeout and leaves the connection clean', function () {
    config(['dbexplorer.limits.admin.timeout' => 1]);

    $failure = null;

    try {
        app(QueryRunner::class)->runStatements('pgsql_admin', [
            "CREATE TABLE {$this->schema}.first (id int)",
            'SELECT pg_sleep(3)',
        ], 'admin', ['label' => 'main']);
    } catch (QueryFailed $e) {
        $failure = $e;
    }

    $pdo = DB::connection('pgsql_admin')->getPdo();

    expect($failure?->sqlState)->toBe('57014')
        ->and($failure?->statement)->toBe(1)
        ->and($failure?->auditId)->toBe(AuditEntry::query()->sole()->id)
        ->and(relationExists("{$this->schema}.first"))->toBeFalse()
        ->and($pdo->inTransaction())->toBeFalse()
        ->and($pdo->query('SHOW statement_timeout')->fetchColumn())->toBe('0');
});

it('S8 still refuses stacked commands inside one statement of the list', function () {
    $failure = null;

    try {
        app(QueryRunner::class)->runStatements('pgsql_admin', ["CREATE TABLE {$this->schema}.a (id int); DROP TABLE {$this->schema}.notes"], 'admin');
    } catch (QueryFailed $e) {
        $failure = $e;
    }

    expect($failure?->sqlState)->toBe('42601')
        ->and(relationExists("{$this->schema}.a"))->toBeFalse()
        ->and(relationExists("{$this->schema}.notes"))->toBeTrue();
});
