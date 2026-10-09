<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Sql\Classifier;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

// S11: the classifier is UX only. These payloads fool it (or the API's
// classifier gate), and the database refuses each one: the editor login has
// no CREATE anywhere and owns nothing, and viewers run in a read-only
// transaction. Each case documents the classifier's verdict, then proves the
// effect (a table `{schema}.evil`, or a server file read) never happened.

/**
 * POST $sql as $role, already confirmed (409) and password-confirmed (423).
 */
function evasionQuery(PgsqlTestCase $test, string $role, string $sql): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token', 'auth.password_confirmed_at' => time()])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/query', ['sql' => $sql, 'confirm' => true]);
}

function evilExists(string $schema): bool
{
    return DB::connection('pgsql_super')->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', ["{$schema}.evil"])->present;
}

beforeEach(function () {
    app()['env'] = 'local';

    // SECURITY INVOKER routines owned by dbx_admin; EXECUTE is granted to
    // PUBLIC by default, so the editor and viewer can call them.
    $this->schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->unprepared(<<<SQL
        CREATE TABLE {$this->schema}.target (id int);
        CREATE FUNCTION {$this->schema}.make_table() RETURNS int LANGUAGE plpgsql AS \$\$
        BEGIN
            CREATE TABLE {$this->schema}.evil (id int);
            RETURN 1;
        END
        \$\$;
        CREATE PROCEDURE {$this->schema}.make_table_proc() LANGUAGE plpgsql AS \$\$
        BEGIN
            CREATE TABLE {$this->schema}.evil (id int);
        END
        \$\$;
        GRANT EXECUTE ON FUNCTION {$this->schema}.make_table() TO dbx_editor, dbx_viewer;
        GRANT EXECUTE ON PROCEDURE {$this->schema}.make_table_proc() TO dbx_editor, dbx_viewer;
        SQL);
});

it('S11 lets the database refuse payloads the classifier misreads, as editor and viewer', function (string $sql, string $class, string $editorState, string $viewerState) {
    $sql = str_replace('{schema}', $this->schema, $sql);

    expect(Classifier::classify($sql))->toBe($class);

    // Viewer first: proves the read-only transaction, then the editor's grants.
    evasionQuery($this, 'viewer', $sql)->assertStatus(422)->assertJsonPath('error.sqlstate', $viewerState);
    evasionQuery($this, 'editor', $sql)->assertStatus(422)->assertJsonPath('error.sqlstate', $editorState);

    expect(evilExists($this->schema))->toBeFalse();
})->with([
    // [SQL, classifier verdict, editor SQLSTATE, viewer SQLSTATE]
    'DDL inside a function, called from SELECT' => ['SELECT {schema}.make_table()', 'read', '42501', '25006'],
    'the function hidden in a CTE' => ['WITH x AS (SELECT {schema}.make_table() AS n) SELECT n FROM x', 'read', '42501', '25006'],
    'the function in VALUES' => ['VALUES ({schema}.make_table())', 'read', '42501', '25006'],
    'the function behind a comment' => ['select/**/{schema}.make_table()', 'read', '42501', '25006'],
    'the function under EXPLAIN ANALYZE' => ['EXPLAIN ANALYZE SELECT {schema}.make_table()', 'read', '42501', '25006'],
    'DDL inside a procedure, via CALL' => ['CALL {schema}.make_table_proc()', 'dml', '42501', '25006'],
    'DDL through query_to_xml' => ["SELECT query_to_xml('CREATE TABLE {schema}.evil (id int)', true, false, '')", 'read', '0A000', '0A000'],
]);

it('S11 lets the database refuse a server file read the classifier calls read', function () {
    $sql = "SELECT lo_import('/etc/hostname')";

    expect(Classifier::classify($sql))->toBe('read');

    foreach (['viewer', 'editor'] as $role) {
        evasionQuery($this, $role, $sql)->assertStatus(422)->assertJsonPath('error.sqlstate', '42501');
    }

    expect(DB::connection('pgsql_super')->selectOne('SELECT count(*) AS n FROM pg_largeobject_metadata')->n)->toBe(0);
});

it('S11 lets the database refuse SQL the classifier cannot place', function () {
    $sql = "LOAD 'auto_explain'";

    expect(Classifier::classify($sql))->toBe('unknown');

    foreach (['viewer', 'editor'] as $role) {
        evasionQuery($this, $role, $sql)->assertStatus(422)->assertJsonPath('error.sqlstate', '42501');
    }
});

it('S11 has the database refuse DDL even when the API gate is bypassed', function (string $sql, string $class, string $viewerState) {
    $sql = str_replace('{schema}', $this->schema, $sql);

    expect(Classifier::classify($sql))->toBe($class);

    // The API answers ddl with 403 (and destructive with 423) before running
    // anything; the runner has no such gate, so this is the database alone.
    foreach (['viewer' => $viewerState, 'editor' => '42501'] as $role => $state) {
        try {
            app(QueryRunner::class)->run("pgsql_{$role}", $sql, [], $role, ['label' => 'main']);
            $failure = null;
        } catch (QueryFailed $e) {
            $failure = $e;
        }

        expect($failure)->not->toBeNull()
            ->and($failure->sqlState)->toBe($state);
    }

    expect(evilExists($this->schema))->toBeFalse()
        ->and(DB::connection('pgsql_super')->selectOne("SELECT to_regclass('{$this->schema}.target') IS NOT NULL AS present")->present)->toBeTrue();
})->with([
    // [SQL, classifier verdict, viewer SQLSTATE]; the editor always gets 42501.
    'CREATE TABLE' => ['CREATE TABLE {schema}.evil (id int)', 'ddl', '25006'],
    'DO block' => ['DO $$ BEGIN CREATE TABLE {schema}.evil (id int); END $$', 'ddl', '25006'],
    'SELECT INTO' => ['SELECT 1 AS id INTO {schema}.evil', 'ddl', '25006'],
    'COPY to a server program' => ["COPY (SELECT 1) TO PROGRAM 'touch /tmp/dbx-evasion'", 'ddl', '42501'],
    'DROP TABLE' => ['DROP TABLE {schema}.target', 'destructive', '25006'],
    'ALTER TABLE' => ['ALTER TABLE {schema}.target ADD COLUMN evil int', 'ddl', '25006'],
    'TRUNCATE' => ['TRUNCATE {schema}.target', 'destructive', '25006'],
]);
