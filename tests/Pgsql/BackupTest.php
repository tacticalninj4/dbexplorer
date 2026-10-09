<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use TacticalNinj4\DbExplorer\Drivers\Postgres\BackupTool;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\Backup;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;

// M4.3, S20: pg_dump through Symfony Process, preflight, retention, and a
// real dump -> pg_restore round-trip.

beforeEach(function () {
    app()['env'] = 'local';

    createUsers(1);
    config(['dbexplorer.backup.disk' => 'local']);
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('backups');
});

/**
 * GET/POST a backups endpoint as $role.
 */
function backupsAs(PgsqlTestCase $test, string $method, string $uri = 'backups', string $role = 'admin'): TestResponse
{
    DbExplorer::roleUsing(fn (): string => $role);

    return $test->actingAs(makeUser())
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->json($method, '/dbexplorer/api/main/'.ltrim($uri, '/'));
}

it('S20 builds the dump command as an argument array, with the password only in the environment', function () {
    $tool = new BackupTool(DB::connection('pgsql_admin'), '/usr/bin/pg_dump');

    $command = $tool->command();
    $env = $tool->env();

    expect($command)->toBe(array_values($command)) // a list; Pest 1 has no toBeList()
        ->and($command[0])->toBe('/usr/bin/pg_dump')
        ->and(in_array('--no-password', $command, true))->toBeTrue();

    // The credentials of the fixture connection never appear in the command
    // line; the password appears only as PGPASSWORD in the environment.
    $line = implode(' ', $command);

    expect($line)->not->toContain('dbx-test-password')
        ->not->toContain('--password')
        ->and($env)->toBe(['PGPASSWORD' => 'dbx-test-password', 'PGSSLMODE' => 'prefer']);

    // Host, port, user and database all reach pg_dump as separate arguments.
    $config = DB::connection('pgsql_admin')->getConfig();

    expect($command)->toContain((string) $config['host'])
        ->and($command)->toContain((string) $config['database']);
});

it('S20 passes the connection\'s SSL settings to pg_dump through the environment, never the arguments', function () {
    config(['database.connections.pgsql_ssl' => array_merge(DB::connection('pgsql_admin')->getConfig(), [
        'sslmode' => 'verify-full',
        'sslrootcert' => '/etc/ssl/root.crt',
        'sslcert' => '/etc/ssl/client.crt',
        'sslkey' => '/etc/ssl/client.key',
    ])]);

    $tool = new BackupTool(DB::connection('pgsql_ssl'), '/usr/bin/pg_dump');

    expect($tool->env())->toBe([
        'PGPASSWORD' => 'dbx-test-password',
        'PGSSLMODE' => 'verify-full',
        'PGSSLROOTCERT' => '/etc/ssl/root.crt',
        'PGSSLCERT' => '/etc/ssl/client.crt',
        'PGSSLKEY' => '/etc/ssl/client.key',
    ])->and(implode(' ', $tool->command()))->not->toContain('ssl')->not->toContain('dbx-test-password');

    // Unset or empty options are left to libpq's defaults.
    config(['database.connections.pgsql_nossl' => array_merge(DB::connection('pgsql_admin')->getConfig(), ['sslmode' => '', 'sslcert' => null])]);

    expect((new BackupTool(DB::connection('pgsql_nossl'), '/usr/bin/pg_dump'))->env())->toBe(['PGPASSWORD' => 'dbx-test-password']);
});

it('preflights the pg_dump version against the server', function () {
    // One major below whatever server the lane runs (PostgreSQL 12 to 18).
    $serverMajor = intdiv((int) DB::connection('pgsql_admin')->selectOne('SHOW server_version_num')->server_version_num, 10000);

    $old = tempnam(sys_get_temp_dir(), 'pgdump-old').'.sh';
    file_put_contents($old, "#!/bin/sh\necho 'pg_dump (PostgreSQL) ".($serverMajor - 1).".9'\n");
    chmod($old, 0755);

    $oldTool = new BackupTool(DB::connection('pgsql_admin'), $old);

    expect($oldTool->preflight())->toContain('older than the server');

    $good = tempnam(sys_get_temp_dir(), 'pgdump-good').'.sh';
    file_put_contents($good, "#!/bin/sh\necho 'pg_dump (PostgreSQL) 99.1'\n");
    chmod($good, 0755);

    expect((new BackupTool(DB::connection('pgsql_admin'), $good))->preflight())->toBeNull();

    unlink($old);
    unlink($good);

    // A missing binary is reported, not thrown.
    expect((new BackupTool(DB::connection('pgsql_admin'), '/nonexistent/pg_dump'))->preflight())->toContain('pg_dump could not be run');
});

it('backs the database up through the job, downloads through the run, and retains the newest', function () {
    config(['dbexplorer.backup.keep' => 2, 'dbexplorer.backup.pg_dump_path' => DB_DUMP_PATH]);

    // No local pg_dump can dump this server (the preflight's own verdict).
    if ((new BackupTool(DB::connection('pgsql_admin'), DB_DUMP_PATH))->preflight() !== null) {
        $this->markTestSkipped('no pg_dump that can dump this server');
    }

    DbExplorer::roleUsing(fn (): string => 'admin');

    foreach ([1, 2, 3] as $i) {
        $run = Run::query()->create([
            'type' => Backup::TYPE,
            'user_id' => '1',
            'label' => 'main',
            'role' => 'admin',
            // dbx_admin owns the fixture schema; the superuser's own tables
            // are out of its reach, so the dump is scoped the way a host app
            // would scope its own schemas.
            'meta' => [Run::USER_PROVIDER => 'users', 'schemas' => ['fixture']],
        ]);

        app()->call([new Backup($run->id), 'handle']);

        expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED, "backup {$i} failed: ".(string) $run->error)
            ->and($run->result_path)->toBe("backups/main/run-{$run->id}.dump");
    }

    $storage = Storage::disk('local');

    expect($storage->files('backups/main'))->toHaveCount(2) // retention kept the newest
        ->and($storage->size($storage->files('backups/main')[0]))->toBeGreaterThan(1000);

    // The result downloads through the runs API (S16).
    $url = backupsAs($this, 'GET', "runs/{$run->id}/download")->assertOk()->json('data.url');

    expect($this->get($url)->getStatusCode())->toBe(200);

    // And the dump is real: pg_restore lists the fixture's tables.
    $restore = (new BackupTool(DB::connection('pgsql_admin'), DB_DUMP_PATH))->restorePath();

    if ($restore !== null) {
        $list = new Process([$restore, '--list', $storage->path((string) $run->result_path)]);
        $list->run();

        expect($list->isSuccessful())->toBeTrue($list->getErrorOutput())
            ->and($list->getOutput())->toContain('TABLE')->toContain('users');
    }
})->skip(DB_DUMP_PATH === '', 'pg_dump not found in PATH');

/**
 * A fake pg_dump: reports $version, and fails a dump with a libpq-style
 * error that quotes the connection's host, port, login and password.
 */
function fakePgDump(string $version): string
{
    $config = DB::connection('pgsql_admin')->getConfig();
    $path = tempnam(sys_get_temp_dir(), 'pgdump-fail');

    file_put_contents($path, "#!/bin/sh\n"
        ."if [ \"\$1\" = --version ]; then echo 'pg_dump (PostgreSQL) {$version}'; exit 0; fi\n"
        ."echo 'pg_dump: error: connection to server at \"{$config['host']}\", port {$config['port']} failed: FATAL:  password authentication failed for user \"{$config['username']}\" (password {$config['password']}) on {$config['database']}' >&2\n"
        ."exit 1\n");
    chmod($path, 0755);

    return $path;
}

it('fails the run with the preflight\'s problem when pg_dump is missing at run time', function () {
    config(['dbexplorer.backup.pg_dump_path' => '/nonexistent/pg_dump']);
    DbExplorer::roleUsing(fn (): string => 'admin');

    $run = Run::query()->create([
        'type' => Backup::TYPE,
        'user_id' => '1',
        'label' => 'main',
        'role' => 'admin',
        'meta' => [Run::USER_PROVIDER => 'users'],
    ]);

    app()->call([new Backup($run->id), 'handle']);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toStartWith('pg_dump could not be run')
        ->and(Storage::disk('local')->exists('backups/main/run-'.$run->id.'.dump'))->toBeFalse();
});

it('fails the run with pg_dump\'s own error, connection details redacted', function () {
    $fake = fakePgDump('99.1');
    config(['dbexplorer.backup.pg_dump_path' => $fake]);
    DbExplorer::roleUsing(fn (): string => 'admin');

    $run = Run::query()->create([
        'type' => Backup::TYPE,
        'user_id' => '1',
        'label' => 'main',
        'role' => 'admin',
        'meta' => [Run::USER_PROVIDER => 'users'],
    ]);

    app()->call([new Backup($run->id), 'handle']);

    $config = DB::connection('pgsql_admin')->getConfig();

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toStartWith('pg_dump failed: pg_dump: error: connection to server at "[redacted]", port [redacted] failed: FATAL:  password authentication failed')
        ->not->toContain((string) $config['host'])
        ->not->toContain((string) $config['username'])
        ->not->toContain((string) $config['password'])
        ->not->toContain((string) $config['database'])
        ->and(Storage::disk('local')->exists('backups/main/run-'.$run->id.'.dump'))->toBeFalse();

    unlink($fake);
});

it('refuses to queue a backup the preflight says would fail, with its message', function (string $path, string $message) {
    Queue::fake();
    config(['dbexplorer.backup.pg_dump_path' => $path === 'old' ? fakePgDump('9.6') : $path]);

    backupsAs($this, 'POST')
        ->assertStatus(422)
        ->assertJsonPath('error.message', fn (string $text): bool => str_contains($text, $message));

    expect(Run::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'binary missing' => ['/nonexistent/pg_dump', 'pg_dump could not be run'],
    'older than the server' => ['old', 'older than the server'],
]);

it('queues a backup when the preflight passes', function () {
    Queue::fake();
    config(['dbexplorer.backup.pg_dump_path' => fakePgDump('99.1')]);

    backupsAs($this, 'POST')->assertStatus(202)->assertJsonPath('data.type', Backup::TYPE);

    Queue::assertPushed(Backup::class);
});

it('answers the backups page for admins only', function (string $role) {
    backupsAs($this, 'GET', 'backups', $role)->assertForbidden();
})->with(['viewer', 'editor']);
