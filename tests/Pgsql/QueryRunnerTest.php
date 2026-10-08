<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Pdo\Pgsql;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryResult;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Events\QueryExecuted;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\QueryHistory;
use TacticalNinj4\DbExplorer\Security\Masker;
use TacticalNinj4\DbExplorer\Tests\PgsqlFixture;

/**
 * Run through the QueryRunner with label "main".
 *
 * @param  array<int|string, scalar|null>  $bindings
 * @param  array<string, mixed>  $options
 */
function runQuery(string $connection, string $sql, string $role, array $bindings = [], array $options = []): QueryResult
{
    return app(QueryRunner::class)->run($connection, $sql, $bindings, $role, $options + ['label' => 'main']);
}

/**
 * The QueryFailed that the run throws, or null when it succeeds.
 *
 * @param  array<int|string, scalar|null>  $bindings
 * @param  array<string, mixed>  $options
 */
function runFailure(string $connection, string $sql, string $role, array $bindings = [], array $options = []): ?QueryFailed
{
    try {
        runQuery($connection, $sql, $role, $bindings, $options);
    } catch (QueryFailed $e) {
        return $e;
    }

    return null;
}

/**
 * Whether the connection is idle: no transaction, no cursor, the session's own timeout.
 */
function expectClean(string $connection): void
{
    $pdo = DB::connection($connection)->getPdo();

    expect($pdo->inTransaction())->toBeFalse()
        ->and((int) $pdo->query('SELECT count(*) FROM pg_cursors WHERE name <> \'\'')->fetchColumn())->toBe(0)
        ->and($pdo->query('SHOW statement_timeout')->fetchColumn())->toBe('0')
        ->and($pdo->query('SHOW transaction_read_only')->fetchColumn())->toBe('off');
}

beforeEach(function () {
    $this->schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$this->schema}.notes (id bigserial PRIMARY KEY, body text)");
    DB::connection('pgsql_admin')->insert("INSERT INTO {$this->schema}.notes (body) VALUES ('one'), ('two'), ('three')");
});

function noteCount(string $schema): int
{
    return (int) DB::connection('pgsql_super')->table("{$schema}.notes")->count();
}

it('S8 runs a single SELECT through a cursor and leaves the connection clean', function () {
    $result = runQuery('pgsql_viewer', "SELECT id, body FROM {$this->schema}.notes ORDER BY id", 'viewer');

    expect($result->rows)->toBe([[1, 'one'], [2, 'two'], [3, 'three']])
        ->and($result->columns)->toBe([
            ['name' => 'id', 'type' => 'int8', 'masked' => false],
            ['name' => 'body', 'type' => 'text', 'masked' => false],
        ])
        ->and($result->rowCount)->toBe(3)
        ->and($result->affectedRows)->toBeNull()
        ->and($result->truncated)->toBeFalse()
        ->and($result->classification)->toBe('read')
        ->and($result->durationMs)->toBeGreaterThan(0)
        ->and($result->auditId)->toBe(AuditEntry::query()->sole()->id);

    expectClean('pgsql_viewer');
});

it('S8 rejects stacked statements and the table survives', function (string $template, string $role) {
    $sql = str_replace('{t}', "{$this->schema}.notes", $template);

    $e = runFailure('pgsql_admin', $sql, $role);

    expect($e)->toBeInstanceOf(QueryFailed::class)
        ->and($e->sqlState)->toBe('42601')
        ->and(noteCount($this->schema))->toBe(3)
        ->and(DB::connection('pgsql_super')->getSchemaBuilder()->hasTable("{$this->schema}.notes"))->toBeTrue();

    expectClean('pgsql_admin');
})->with([
    'select then drop' => ['SELECT 1; DROP TABLE {t}', 'admin'],
    'line comment hides the drop' => ["SELECT 1;--\nDROP TABLE {t}", 'admin'],
    'statement after a block comment' => ['/* report */ SELECT 1; /* cleanup */ DELETE FROM {t}', 'admin'],
    'commit then write' => ['COMMIT; DELETE FROM {t}', 'viewer'],
    'read classified, writes after' => ['SELECT 1; SELECT 2; TRUNCATE {t}', 'editor'],
]);

it('S8 rejects stacked statements even when the connection emulates prepares', function () {
    config(['database.connections.pgsql_emulated' => PgsqlFixture::connection(DB::connection('pgsql_admin')->getDatabaseName(), 'dbx_admin', PgsqlFixture::PASSWORD) + [
        'options' => [PDO::ATTR_EMULATE_PREPARES => true],
    ]]);

    $e = runFailure('pgsql_emulated', "DELETE FROM {$this->schema}.notes WHERE id = 1; DELETE FROM {$this->schema}.notes", 'admin');

    expect($e?->sqlState)->toBe('42601')
        ->and($e?->getMessage())->toContain('cannot insert multiple commands into a prepared statement')
        ->and(noteCount($this->schema))->toBe(3);
});

it('S9 lets an editor write and commits it', function () {
    $result = runQuery('pgsql_editor', "UPDATE {$this->schema}.notes SET body = 'changed' WHERE id = 1", 'editor');

    expect($result->affectedRows)->toBe(1)
        ->and($result->columns)->toBe([])
        ->and($result->classification)->toBe('dml')
        ->and(DB::connection('pgsql_super')->table("{$this->schema}.notes")->where('id', 1)->value('body'))->toBe('changed')
        ->and(AuditEntry::query()->sole()->row_count)->toBe(1);

    expectClean('pgsql_editor');
});

it('S9 blocks viewer writes in the read-only transaction even on a login with write grants', function (string $template) {
    $e = runFailure('pgsql_editor', str_replace('{t}', "{$this->schema}.notes", $template), 'viewer');

    expect($e?->sqlState)->toBe('25006')
        ->and($e?->getMessage())->toContain('read-only transaction')
        ->and(noteCount($this->schema))->toBe(3)
        ->and(DB::connection('pgsql_super')->table("{$this->schema}.notes")->where('id', 1)->value('body'))->toBe('one');

    expectClean('pgsql_editor');
})->with([
    'update' => ["UPDATE {t} SET body = 'x' WHERE id = 1"],
    'insert' => ["INSERT INTO {t} (body) VALUES ('x')"],
    'delete' => ['DELETE FROM {t} WHERE id = 1'],
    'writable CTE' => ['WITH d AS (DELETE FROM {t} RETURNING id) SELECT * FROM d'],
]);

it('S9 blocks a viewer calling a function that writes, even as the owner login', function () {
    $before = DB::connection('pgsql_super')->table('fixture.counters')->where('name', 'sneaky')->value('value');

    $e = runFailure('pgsql_admin', 'SELECT fixture.sneaky()', 'viewer');

    expect($e?->sqlState)->toBe('25006')
        ->and(DB::connection('pgsql_super')->table('fixture.counters')->where('name', 'sneaky')->value('value'))->toBe($before);
});

it('S9 does not let a viewer switch the transaction to read-write', function (string $sql) {
    $e = runFailure('pgsql_editor', $sql, 'viewer');

    expect($e?->sqlState)->toBe('25001')
        ->and($e?->getMessage())->toContain('must be set before any query');

    expectClean('pgsql_editor');
})->with([
    'SET TRANSACTION' => ['SET TRANSACTION READ WRITE'],
    'SET transaction_read_only' => ['SET transaction_read_only = off'],
]);

it('S9 rolls back session settings a viewer changes', function () {
    $pdo = DB::connection('pgsql_viewer')->getPdo();
    $path = $pdo->query('SHOW search_path')->fetchColumn();

    runQuery('pgsql_viewer', 'SET search_path = fixture', 'viewer');
    runQuery('pgsql_viewer', 'SET SESSION statement_timeout = 0', 'viewer');

    expect($pdo->query('SHOW search_path')->fetchColumn())->toBe($path);
    expectClean('pgsql_viewer');
});

it('S10 cancels a statement that runs past the role timeout', function () {
    config(['dbexplorer.limits.viewer.timeout' => 1]);
    $started = microtime(true);

    $e = runFailure('pgsql_viewer', 'SELECT pg_sleep(5)', 'viewer');

    expect($e?->sqlState)->toBe('57014')
        ->and(microtime(true) - $started)->toBeLessThan(4.0)
        ->and(AuditEntry::query()->sole()->outcome)->toBe('error');

    expectClean('pgsql_viewer');
});

it('S10 caps the rows of a 1M-row table, sets truncated and keeps memory flat', function () {
    config(['dbexplorer.limits.viewer.rows' => 1000]);
    $start = memory_get_usage();

    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }

    $result = runQuery('pgsql_viewer', 'SELECT * FROM fixture.big ORDER BY id', 'viewer');

    expect($result->rowCount)->toBe(1000)
        ->and($result->truncated)->toBeTrue()
        ->and($result->rows[999][0])->toBe(1000)
        ->and(memory_get_peak_usage() - $start)->toBeLessThan(16 * 1024 * 1024)
        ->and(AuditEntry::query()->sole()->meta)->toBe(['truncated' => true]);
});

it('S10 does not flag a result that exactly fills the cap', function () {
    config(['dbexplorer.limits.viewer.rows' => 3]);

    $result = runQuery('pgsql_viewer', "SELECT * FROM {$this->schema}.notes", 'viewer');

    expect($result->rowCount)->toBe(3)->and($result->truncated)->toBeFalse();
});

it('S10 fetches only the capped rows of a huge cross join', function () {
    config(['dbexplorer.limits.viewer.rows' => 50, 'dbexplorer.limits.viewer.timeout' => 10]);

    $result = runQuery('pgsql_viewer', 'SELECT a.id, b.id FROM fixture.big a CROSS JOIN fixture.big b', 'viewer');
    // Evaluating past the cap would divide by zero.
    $lazy = runQuery('pgsql_viewer', 'SELECT CASE WHEN g > 51 THEN 1 / (g - g) ELSE g END FROM generate_series(1, 1000) g', 'viewer');

    expect($result->rowCount)->toBe(50)
        ->and($result->truncated)->toBeTrue()
        ->and($lazy->rowCount)->toBe(50)
        ->and($lazy->truncated)->toBeTrue();
});

it('S10 caps rows on the prepared path too', function () {
    config(['dbexplorer.limits.editor.rows' => 2]);

    $result = runQuery('pgsql_editor', "INSERT INTO {$this->schema}.notes (body) VALUES ('a'), ('b'), ('c') RETURNING id", 'editor');

    expect($result->rowCount)->toBe(2)
        ->and($result->truncated)->toBeTrue()
        ->and($result->affectedRows)->toBe(3)
        ->and(noteCount($this->schema))->toBe(6);
});

it('rejects a misconfigured limit', function () {
    config(['dbexplorer.limits.viewer.timeout' => '0; SET x = 1']);

    runQuery('pgsql_viewer', 'SELECT 1', 'viewer');
})->throws(InvalidArgumentException::class, 'dbexplorer.limits.viewer.timeout must be a positive integer.');

it('rolls back EXPLAIN ANALYZE of a DELETE and returns the plan', function () {
    $result = runQuery('pgsql_admin', "DELETE FROM {$this->schema}.notes", 'admin', [], ['explain' => 'analyze']);

    expect($result->explain)->toBe('analyze')
        ->and($result->plan[0]['Plan']['Node Type'] ?? null)->toBe('ModifyTable')
        ->and($result->plan[0]['Plan']['Actual Rows'] ?? null)->toEqual(0)
        ->and($result->plan[0])->toHaveKey('Execution Time')
        ->and($result->classification)->toBe('destructive')
        ->and(noteCount($this->schema))->toBe(3)
        ->and(AuditEntry::query()->sole()->meta)->toBe(['explain' => 'analyze']);

    expectClean('pgsql_admin');
});

it('rolls back a typed EXPLAIN ANALYZE of a DELETE', function () {
    runQuery('pgsql_admin', "EXPLAIN ANALYZE DELETE FROM {$this->schema}.notes", 'admin');

    expect(noteCount($this->schema))->toBe(3);
});

it('plans without running for plain EXPLAIN, with bindings', function () {
    $result = runQuery('pgsql_admin', "DELETE FROM {$this->schema}.notes WHERE id = ?", 'admin', [1], ['explain' => 'plain']);

    expect($result->plan[0]['Plan']['Node Type'] ?? null)->toBe('ModifyTable')
        ->and($result->plan[0])->not->toHaveKey('Execution Time')
        ->and(noteCount($this->schema))->toBe(3);
});

it('rolls back on error and leaves the connection usable', function () {
    $e = runFailure('pgsql_editor', "UPDATE {$this->schema}.notes SET body = (1 / (id - 2))::text", 'editor');

    expect($e?->sqlState)->toBe('22012')
        ->and(DB::connection('pgsql_super')->table("{$this->schema}.notes")->orderBy('id')->pluck('body')->all())->toBe(['one', 'two', 'three']);

    expectClean('pgsql_editor');

    expect(runQuery('pgsql_editor', 'SELECT 42', 'editor')->rows)->toBe([[42]]);
});

it('fails COPY to the client cleanly and leaves the connection usable', function () {
    $e = runFailure('pgsql_viewer', "COPY {$this->schema}.notes TO STDOUT", 'viewer');

    expect($e?->sqlState)->toBe('HY000')
        ->and($e?->getMessage())->toContain('COPY');

    expect(runQuery('pgsql_viewer', 'SELECT 1', 'viewer')->rows)->toBe([[1]]);
    expectClean('pgsql_viewer');
});

it('refuses to run inside a transaction the app opened on the connection', function () {
    DB::connection('pgsql_editor')->beginTransaction();

    try {
        $e = runFailure('pgsql_editor', "DELETE FROM {$this->schema}.notes", 'editor');
    } finally {
        DB::connection('pgsql_editor')->rollBack();
    }

    expect($e?->getMessage())->toBe('The connection already has an open transaction.')
        ->and(noteCount($this->schema))->toBe(3);
});

it('reports the error position in the submitted SQL, not the cursor wrapper', function () {
    $e = runFailure('pgsql_viewer', "SELECT id,\n  (body FROM {$this->schema}.notes", 'viewer');

    expect($e?->sqlState)->toBe('42601')
        ->and($e?->getMessage())->toBe('syntax error at or near "FROM"')
        ->and($e?->lineNumber)->toBe(2)
        ->and($e?->position)->toBe(strpos("SELECT id,\n  (body FROM", 'FROM') + 1);
});

it('rejects empty SQL', function (string $sql) {
    expect(runFailure('pgsql_viewer', $sql, 'viewer')?->getMessage())->toBe('There is no statement to run.');
})->with(['empty' => [''], 'whitespace' => ["  \n"], 'comment' => ['-- nothing']]);

it('binds positional and named values inside the cursor', function () {
    $positional = runQuery('pgsql_viewer', "SELECT body FROM {$this->schema}.notes WHERE id > ? AND body <> ? ORDER BY id", 'viewer', [1, 'three']);
    $named = runQuery('pgsql_viewer', 'SELECT :n::int + 1, :flag::boolean, :none::text IS NULL', 'viewer', ['n' => 41, ':flag' => false, 'none' => null]);
    // An injection attempt stays a value.
    $injection = runQuery('pgsql_viewer', "SELECT count(*) FROM {$this->schema}.notes WHERE body = ?", 'viewer', ["one' OR '1'='1"]);

    expect($positional->rows)->toBe([['two']])
        ->and($named->rows)->toBe([[42, false, true]])
        ->and($injection->rows)->toBe([[0]]);
});

it('rejects bindings that are not scalars', function () {
    runQuery('pgsql_viewer', 'SELECT ?', 'viewer', [['array']]);
})->throws(InvalidArgumentException::class);

it('returns bytea as hex text', function () {
    expect(runQuery('pgsql_viewer', "SELECT '\\xdeadbeef'::bytea, NULL::bytea", 'viewer')->rows)->toBe([['\\xdeadbeef', null]]);
});

it('S14 masks result columns unless unmasked', function () {
    $masked = runQuery('pgsql_viewer', 'SELECT id, password, api_token FROM fixture.users WHERE id = 1', 'viewer');
    $unmasked = runQuery('pgsql_admin', 'SELECT id, password FROM fixture.users WHERE id = 1', 'admin', [], ['unmask' => true]);

    expect($masked->rows)->toBe([[1, Masker::MASK, Masker::MASK]])
        ->and(array_column($masked->columns, 'masked'))->toBe([false, true, true])
        ->and($unmasked->rows[0][1])->toStartWith('$2y$12$alice')
        ->and($unmasked->columns[1]['masked'])->toBeFalse()
        ->and(AuditEntry::query()->orderByDesc('id')->first()?->meta)->toBe(['unmasked' => true]);
});

it('S17 writes an audit row and a history row without bound values', function () {
    runQuery('pgsql_viewer', 'SELECT id FROM fixture.users WHERE email = ?', 'viewer', ['binding-secret@example.com']);
    $e = runFailure('pgsql_viewer', 'SELECT ?::int', 'viewer', ['binding-secret-not-a-number']);

    $audits = AuditEntry::query()->orderBy('id')->get();
    $history = QueryHistory::query()->orderBy('id')->get();

    expect($e?->sqlState)->toBe('22P02')
        ->and($audits)->toHaveCount(2)
        ->and($history)->toHaveCount(2)
        ->and($audits[0]->only(['action', 'role', 'label', 'sql', 'classification', 'row_count', 'outcome', 'error']))->toBe([
            'action' => 'query',
            'role' => 'viewer',
            'label' => 'main',
            'sql' => 'SELECT id FROM fixture.users WHERE email = ?',
            'classification' => 'read',
            'row_count' => 0,
            'outcome' => 'success',
            'error' => null,
        ])
        ->and($audits[0]->meta)->toBe(['bindings' => 1])
        ->and($audits[1]->outcome)->toBe('error')
        ->and($audits[1]->error)->toBe('22P02: invalid input syntax for type integer: "?"')
        ->and($e?->auditId)->toBe($audits[1]->id)
        ->and($history[1]->only(['label', 'role', 'sql', 'classification', 'outcome', 'error']))->toBe([
            'label' => 'main',
            'role' => 'viewer',
            'sql' => 'SELECT ?::int',
            'classification' => 'read',
            'outcome' => 'error',
            'error' => '22P02: invalid input syntax for type integer: "?"',
        ])
        ->and(json_encode([$audits->toArray(), $history->toArray()]))->not->toContain('binding-secret');
});

it('fires QueryExecuted after the audit row with a payload free of values, bindings and config', function () {
    Event::fake([QueryExecuted::class]);

    $result = runQuery('pgsql_viewer', 'SELECT id, password FROM fixture.users WHERE name = ?', 'viewer', ['Alice']);
    runFailure('pgsql_viewer', 'SELECT nope', 'viewer');

    $events = Event::dispatched(QueryExecuted::class)->map(fn (array $args): QueryExecuted => $args[0]);
    $first = $events->first();
    $payload = json_encode(get_object_vars($first));
    $database = config('database.connections.pgsql_viewer');

    expect($events)->toHaveCount(2)
        ->and($first->auditId)->toBe($result->auditId)
        ->and(AuditEntry::query()->find($first->auditId))->not->toBeNull()
        ->and(get_object_vars($first))->toBe([
            'auditId' => $result->auditId,
            'userId' => null,
            'label' => 'main',
            'role' => 'viewer',
            'classification' => 'read',
            'sql' => 'SELECT id, password FROM fixture.users WHERE name = ?',
            'durationMs' => $first->durationMs,
            'rowCount' => 1,
            'outcome' => 'success',
        ])
        ->and($events->last()->outcome)->toBe('error')
        ->and($payload)->not->toContain('Alice')
        ->and($payload)->not->toContain('$2y$')
        ->and($payload)->not->toContain($database['password'])
        ->and($payload)->not->toContain($database['username'])
        ->and($payload)->not->toContain('pgsql_viewer');
});

it('S7 reports a connection failure without the connection config', function () {
    config(['database.connections.pgsql_broken' => PgsqlFixture::connection('dbx_no_such_database', 'dbx_viewer', 'wrong-password-xyz')]);

    $e = runFailure('pgsql_broken', 'SELECT 1', 'viewer');

    expect($e?->getMessage())->toBe('Could not connect to the database.')
        ->and($e?->getPrevious())->toBeNull()
        ->and(json_encode(AuditEntry::query()->sole()->toArray()))->not->toContain('dbx_no_such_database')
        ->and(AuditEntry::query()->sole()->error)->toBe('08006: Could not connect to the database.');
});

it('captures NOTICEs when the PDO supports it', function () {
    $result = runQuery('pgsql_admin', "DO \$\$ BEGIN RAISE NOTICE 'hello %', 42; RAISE WARNING 'careful'; END \$\$", 'admin');
    $supported = DB::connection('pgsql_admin')->getPdo() instanceof Pgsql;

    expect($result->noticesSupported)->toBe($supported)
        ->and($result->notices)->toBe($supported ? ['NOTICE:  hello 42', 'WARNING:  careful'] : []);

    // Only the statement's own notices, and the callback is removed afterwards.
    expect(runQuery('pgsql_admin', 'SELECT 1', 'admin')->notices)->toBe([]);
});
