<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
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

    expect($command)->toBeList()
        ->and($command[0])->toBe('/usr/bin/pg_dump')
        ->and(in_array('--no-password', $command, true))->toBeTrue();

    // The credentials of the fixture connection never appear in the command
    // line; the password appears only as PGPASSWORD in the environment.
    $line = implode(' ', $command);

    expect($line)->not->toContain('dbx-test-password')
        ->not->toContain('--password')
        ->and($env)->toBe(['PGPASSWORD' => 'dbx-test-password']);

    // Host, port, user and database all reach pg_dump as separate arguments.
    $config = DB::connection('pgsql_admin')->getConfig();

    expect($command)->toContain((string) $config['host'])
        ->and($command)->toContain((string) $config['database']);
});

it('preflights the pg_dump version against the server', function () {
    $old = tempnam(sys_get_temp_dir(), 'pgdump-old').'.sh';
    file_put_contents($old, "#!/bin/sh\necho 'pg_dump (PostgreSQL) 12.9'\n");
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

it('fails the run when pg_dump itself fails', function () {
    config(['dbexplorer.backup.pg_dump_path' => '/nonexistent/pg_dump']);

    $run = Run::query()->create([
        'type' => Backup::TYPE,
        'user_id' => '1',
        'label' => 'main',
        'role' => 'admin',
        'meta' => [Run::USER_PROVIDER => 'users'],
    ]);

    app()->call([new Backup($run->id), 'handle']);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->not->toBeNull()
        ->and(Storage::disk('local')->exists('backups'))->toBeFalse();
});

it('answers the backups page for admins only', function (string $role) {
    backupsAs($this, 'GET', 'backups', $role)->assertForbidden();
})->with(['viewer', 'editor']);
