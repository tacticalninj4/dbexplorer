<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Tests\PgsqlFixture;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

// M4.4: the active sessions API, against the fixture Postgres.

beforeEach(function () {
    app()['env'] = 'local';

    // A second backend on the admin login, separate from every connection
    // the API itself uses, so cancelling it can never cancel the caller.
    config(['database.connections.pgsql_admin2' => PgsqlFixture::roleConnections(PgsqlTestCase::database())['admin']]);
});

afterEach(function () {
    try {
        DB::disconnect('pgsql_admin2');
    } catch (Throwable) {
        // already gone (terminated by a test, perhaps)
    }
});

/**
 * The pid of a separate sleeping backend on the admin login.
 */
function secondAdminPid(): int
{
    return (int) DB::connection('pgsql_admin2')->selectOne('SELECT pg_backend_pid() AS pid')->pid;
}

/**
 * Call a sessions endpoint as $role (admin by default).
 */
function sessionsAs(PgsqlTestCase $test, string $method, string $uri, string $role = 'admin', bool $confirmed = true): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'] + ($confirmed ? ['auth.password_confirmed_at' => time()] : []))
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->json($method, '/dbexplorer/api/main/'.ltrim($uri, '/'));
}

it('lists this database\'s sessions for an admin, and no one else', function () {
    $data = sessionsAs($this, 'GET', 'sessions')->assertOk()->json('data');

    $own = (int) DB::connection('pgsql_admin')->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $other = secondAdminPid();

    expect($data)->not->toBeEmpty()
        ->and($data[0])->toHaveKeys(['pid', 'usename', 'state', 'query'])
        // The API's own backend is never listed.
        ->and(collect($data)->pluck('pid'))->not->toContain($own)
        ->and($other)->toBeInt();

    sessionsAs($this, 'GET', 'sessions', 'editor')->assertForbidden();
    sessionsAs($this, 'GET', 'sessions', 'viewer')->assertForbidden();
});

it('cancels a sleeping backend of the same login, audited, and only that login\'s', function () {
    $pid = secondAdminPid();

    sessionsAs($this, 'POST', "sessions/{$pid}/cancel")->assertNoContent();

    expect(AuditEntry::query()->sole())
        ->action->toBe('session.cancel')
        ->and(AuditEntry::query()->sole()->meta)->toBe(['existed' => true, 'pid' => $pid]);

    // A pid that is not this login's (or none at all) is a 404.
    sessionsAs($this, 'POST', 'sessions/999999/cancel')->assertNotFound();
    sessionsAs($this, 'POST', 'sessions/abc/cancel')->assertNotFound();

    expect(AuditEntry::query()->orderBy('id')->pluck('meta')->all())->toBe([
        ['existed' => true, 'pid' => $pid],
        ['existed' => false, 'pid' => 999999],
    ]);
});

it('S5 refuses to terminate without a recent password confirmation, then terminates', function () {
    $pid = secondAdminPid();

    sessionsAs($this, 'POST', "sessions/{$pid}/terminate", 'admin', false)
        ->assertStatus(423)
        ->assertExactJson(['message' => 'Password confirmation required.']);

    expect(AuditEntry::query()->count())->toBe(0);

    sessionsAs($this, 'POST', "sessions/{$pid}/terminate")->assertNoContent();

    expect(AuditEntry::query()->sole()->action)->toBe('session.terminate');

    // The backend is gone.
    expect(DB::connection('pgsql_super')->selectOne('SELECT 1 FROM pg_stat_activity WHERE pid = ?', [$pid]))->toBeNull();
});
