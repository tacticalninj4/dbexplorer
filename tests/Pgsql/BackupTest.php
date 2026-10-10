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
use TacticalNinj4\DbExplorer\Tests\PgsqlFixture;
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
 * The pg_restore that pairs with DB_DUMP_PATH: the docker wrapper's
 * counterpart, or the pg_restore next to pg_dump. Null when there is none.
 */
function pairedPgRestore(): ?string
{
    if (basename(DB_DUMP_PATH) === 'docker-pgdump.sh') {
        return dirname(DB_DUMP_PATH).'/docker-pgrestore.sh';
    }

    return (new BackupTool(DB::connection('pgsql_admin'), DB_DUMP_PATH))->restorePath();
}

/**
 * Row count and an md5 of the ordered contents of each scratch table, on $connection.
 *
 * @return array<string, array{0: int, 1: string}>
 */
function backupFingerprint(string $connection, string $schema): array
{
    $tables = [];

    foreach (['teams', 'members'] as $table) {
        $row = DB::connection($connection)->selectOne(
            "SELECT count(*) AS n, md5(coalesce(string_agg(t::text, '|' ORDER BY t.id), '')) AS digest FROM {$schema}.{$table} t",
        );

        $tables[$table] = [(int) $row->n, (string) $row->digest];
    }

    return $tables;
}

it('round-trips a backup: the dump restores with pg_restore into a fresh database with the same rows', function () {
    config(['dbexplorer.backup.pg_dump_path' => DB_DUMP_PATH]);

    $problem = (new BackupTool(DB::connection('pgsql_admin'), DB_DUMP_PATH))->preflight();

    if ($problem !== null) {
        $this->markTestSkipped("no pg_dump that can dump this server: {$problem}");
    }

    $restore = pairedPgRestore();

    if ($restore === null || ! is_executable($restore)) {
        $this->markTestSkipped('no pg_restore next to '.DB_DUMP_PATH);
    }

    $schema = $this->scratchSchema();

    DB::connection('pgsql_admin')->unprepared(<<<SQL
        CREATE TABLE {$schema}.teams (id serial PRIMARY KEY, name text NOT NULL UNIQUE);
        CREATE TABLE {$schema}.members (
            id bigserial PRIMARY KEY,
            team_id int NOT NULL REFERENCES {$schema}.teams (id),
            email text NOT NULL,
            profile jsonb,
            joined timestamptz NOT NULL DEFAULT now()
        );
        INSERT INTO {$schema}.teams (name) SELECT 'team ' || g FROM generate_series(1, 7) g;
        INSERT INTO {$schema}.members (team_id, email, profile)
            SELECT 1 + g % 7, 'user' || g || '@example.com', jsonb_build_object('n', g, 'note', E'tab\\there, quote '' and backslash \\\\')
            FROM generate_series(1, 2500) g;
        CREATE VIEW {$schema}.team_sizes AS SELECT team_id, count(*) AS members FROM {$schema}.members GROUP BY team_id;
        SQL);

    DbExplorer::roleUsing(fn (): string => 'admin');

    $run = Run::query()->create([
        'type' => Backup::TYPE,
        'user_id' => '1',
        'label' => 'main',
        'role' => 'admin',
        'meta' => [Run::USER_PROVIDER => 'users', 'schemas' => [$schema]],
    ]);

    app()->call([new Backup($run->id), 'handle']);

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED, (string) $run->error);

    $database = 'dbx_restore_'.bin2hex(random_bytes(5));
    $super = DB::connection('pgsql_super')->getConfig();

    DB::connection('pgsql_super')->statement("CREATE DATABASE {$database}");

    try {
        // The documented restore (docs/security.md §7), into a database of our choosing.
        $process = new Process([
            $restore,
            '--host', (string) $super['host'],
            '--port', (string) $super['port'],
            '--username', (string) $super['username'],
            '--dbname', $database,
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            Storage::disk('local')->path((string) $run->result_path),
        ], null, ['PGPASSWORD' => (string) $super['password']], null, 120.0);
        $process->run();

        expect($process->isSuccessful())->toBeTrue('pg_restore failed: '.$process->getErrorOutput());

        config(['database.connections.pgsql_restored' => PgsqlFixture::connection($database)]);

        $original = backupFingerprint('pgsql_admin', $schema);
        $restored = DB::connection('pgsql_restored');

        expect([$original['teams'][0], $original['members'][0]])->toBe([7, 2500])
            ->and(backupFingerprint('pgsql_restored', $schema))->toBe($original)
            ->and((int) $restored->selectOne("SELECT sum(members) AS n FROM {$schema}.team_sizes")->n)->toBe(2500)
            // Sequences come back at their values, so new rows don't collide.
            ->and((int) $restored->selectOne("SELECT nextval('{$schema}.members_id_seq') AS id")->id)->toBe(2501);
    } finally {
        DB::disconnect('pgsql_restored');
        DB::connection('pgsql_super')->statement("DROP DATABASE IF EXISTS {$database}");
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
