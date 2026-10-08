<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryResult;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Events\QueryExecuted;
use TacticalNinj4\DbExplorer\Exceptions\HiddenObjectReferenced;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Models\AuditEntry;

/**
 * @param  array<int|string, scalar|null>  $bindings
 * @param  array<string, mixed>  $options
 */
function hiddenRun(string $sql, string $role = 'viewer', array $bindings = [], array $options = []): QueryResult
{
    return app(QueryRunner::class)->run("pgsql_{$role}", $sql, $bindings, $role, $options + ['label' => 'main']);
}

/**
 * @param  array<int|string, scalar|null>  $bindings
 * @param  array<string, mixed>  $options
 */
function hiddenFailure(string $sql, string $role = 'viewer', array $bindings = [], array $options = []): ?QueryFailed
{
    try {
        hiddenRun($sql, $role, $bindings, $options);
    } catch (QueryFailed $e) {
        return $e;
    }

    return null;
}

beforeEach(function () {
    config(['dbexplorer.hidden' => ['fixture.secrets']]);
});

it('S13 rejects queries that read a hidden table', function (string $sql, string $role) {
    $e = hiddenFailure($sql, $role);

    expect($e)->toBeInstanceOf(HiddenObjectReferenced::class)
        ->and($e?->sqlState)->toBe('42P01')
        ->and($e?->getMessage())->toBe('relation "fixture.secrets" does not exist')
        ->and(DB::connection("pgsql_{$role}")->getPdo()->inTransaction())->toBeFalse();
})->with([
    'direct' => ['SELECT * FROM fixture.secrets', 'viewer'],
    'quoted and upper-case schema' => ['SELECT * FROM FIXTURE."secrets"', 'viewer'],
    'CTE' => ['WITH s AS (SELECT * FROM fixture.secrets) SELECT label FROM s', 'viewer'],
    'subquery' => ['SELECT (SELECT max(value) FROM fixture.secrets) AS v', 'viewer'],
    'EXISTS' => ["SELECT 1 WHERE EXISTS (SELECT 1 FROM fixture.secrets WHERE value LIKE 'a%')", 'viewer'],
    'join' => ['SELECT u.id, s.value FROM fixture.users u JOIN fixture.secrets s ON s.id = u.id', 'viewer'],
    'typed EXPLAIN' => ['EXPLAIN (COSTS OFF) SELECT * FROM fixture.secrets', 'viewer'],
    'typed EXPLAIN ANALYZE' => ['EXPLAIN ANALYZE SELECT * FROM fixture.secrets', 'viewer'],
    'DELETE target' => ['DELETE FROM fixture.secrets WHERE id = 1', 'admin'],
    'INSERT … SELECT source' => ['INSERT INTO fixture.counters (name, value) SELECT label, 1 FROM fixture.secrets', 'admin'],
    'DECLARE' => ['DECLARE c CURSOR FOR SELECT * FROM fixture.secrets', 'viewer'],
]);

it('S13 rejects a hidden table read through a view, naming no position', function () {
    $e = hiddenFailure('SELECT * FROM fixture.secret_labels');

    expect($e)->toBeInstanceOf(HiddenObjectReferenced::class)
        ->and($e?->getMessage())->toBe('relation "fixture.secrets" does not exist')
        ->and($e?->position)->toBeNull();
});

it('S13 rejects CREATE TABLE AS over a hidden table, and bound queries', function () {
    $schema = $this->scratchSchema();

    expect(hiddenFailure("CREATE TABLE {$schema}.copy AS SELECT * FROM fixture.secrets", 'admin'))->toBeInstanceOf(HiddenObjectReferenced::class)
        ->and(hiddenFailure('SELECT value FROM fixture.secrets WHERE id = ?', 'viewer', [1]))->toBeInstanceOf(HiddenObjectReferenced::class)
        ->and(hiddenFailure('SELECT * FROM fixture.secrets', 'viewer', [], ['explain' => 'plain']))->toBeInstanceOf(HiddenObjectReferenced::class)
        ->and(DB::connection('pgsql_super')->getSchemaBuilder()->hasTable("{$schema}.copy"))->toBeFalse();
});

it('S13 answers a hidden table exactly like a missing one', function () {
    $hidden = hiddenFailure("SELECT id,\n  value FROM fixture.secrets");
    $missing = hiddenFailure("SELECT id,\n  value FROM fixture.nothing");

    expect($missing)->not->toBeInstanceOf(HiddenObjectReferenced::class)
        ->and($hidden?->sqlState)->toBe($missing?->sqlState)
        ->and($hidden?->getMessage())->toBe(str_replace('nothing', 'secrets', (string) $missing?->getMessage()))
        ->and($hidden?->position)->toBe($missing?->position)
        ->and($hidden?->lineNumber)->toBe($missing?->lineNumber)
        ->and(hiddenFailure('select * from secrets_view_does_not_matter_secrets'))->not->toBeInstanceOf(HiddenObjectReferenced::class);
});

it('S13 hides a whole schema with a schema pattern', function () {
    config(['dbexplorer.hidden' => ['fixture']]);

    expect(hiddenFailure('SELECT count(*) FROM fixture.users'))->toBeInstanceOf(HiddenObjectReferenced::class);
});

it('S13 lets other queries through, and skips the check when nothing is hidden', function () {
    expect(hiddenRun('SELECT count(*) FROM fixture.users')->rows)->toBe([[3]])
        ->and(hiddenRun("UPDATE fixture.counters SET value = value WHERE name = 'visits'", 'editor')->affectedRows)->toBe(1);

    config(['dbexplorer.hidden' => []]);

    expect(hiddenRun('SELECT count(*) FROM fixture.secrets')->rowCount)->toBe(1);
});

it('S13 audits the hidden relation and fires QueryExecuted as an error', function () {
    Event::fake([QueryExecuted::class]);

    $e = hiddenFailure('SELECT * FROM fixture.secret_labels');
    $entry = AuditEntry::query()->sole();

    expect($entry->outcome)->toBe('error')
        ->and($entry->error)->toBe('42P01: relation "fixture.secrets" does not exist')
        ->and($entry->meta)->toBe(['hidden_object' => 'fixture.secrets', 'sqlstate' => '42P01'])
        ->and($e?->auditId)->toBe($entry->id);

    Event::assertDispatched(QueryExecuted::class, fn (QueryExecuted $event): bool => $event->auditId === $entry->id && $event->outcome === 'error');
});

it('S13 GAP: does not catch a function that reads a hidden table (docs/security.md)', function () {
    $schema = $this->scratchSchema();
    DB::connection('pgsql_admin')->unprepared(<<<SQL
        CREATE FUNCTION {$schema}.peek() RETURNS text LANGUAGE plpgsql STABLE AS \$\$
        BEGIN RETURN (SELECT value FROM fixture.secrets ORDER BY id LIMIT 1); END
        \$\$;
        SQL);

    $result = hiddenRun("SELECT {$schema}.peek()");

    expect($result->rows[0][0])->toBe(DB::connection('pgsql_super')->table('fixture.secrets')->orderBy('id')->value('value'));
});

it('S13 GAP: does not check statements EXPLAIN refuses, such as CREATE VIEW, but reading through the view is caught', function () {
    $schema = $this->scratchSchema();

    hiddenRun("CREATE VIEW {$schema}.leak AS SELECT * FROM fixture.secrets", 'admin');

    expect(hiddenFailure("SELECT * FROM {$schema}.leak", 'admin'))->toBeInstanceOf(HiddenObjectReferenced::class);
});
