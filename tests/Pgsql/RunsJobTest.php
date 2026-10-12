<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use TacticalNinj4\DbExplorer\Export\ExportWriter;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\Backup;
use TacticalNinj4\DbExplorer\Jobs\Export;
use TacticalNinj4\DbExplorer\Jobs\RunQuery;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Tests\Jobs\RunStatements;

beforeEach(function () {
    app()['env'] = 'local';
});

/**
 * The database queue this process dispatches to and a real worker reads from.
 */
function createQueueTables(): void
{
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at')->index();
        $table->unsignedInteger('created_at')->index();
    });
    Schema::create('failed_jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    config(['queue.default' => 'database']);
}

/**
 * Start a child `queue:work --once` that picks up the one queued job.
 */
function startWorker(): Process
{
    $worker = new Process(
        [PHP_BINARY, __DIR__.'/scripts/work-one-job.php'],
        null,
        ['TEST_TOKEN' => getenv('TEST_TOKEN') ?: ''],
        null,
        90,
    );
    $worker->start();

    return $worker;
}

it('cancels a running query mid-statement from the API, with a real queue worker', function () {
    createQueueTables();
    createUsers(1);

    $run = createRun(['meta' => [Run::USER_PROVIDER => 'users', 'statements' => ['SELECT pg_sleep(30)']]]);

    RunStatements::dispatch($run->id);
    $worker = startWorker();

    // Wait until the stored pid's backend is really inside the sleep, not
    // between statements.
    $deadline = microtime(true) + 30;
    $sleeping = [];

    do {
        usleep(100000);
        $fresh = Run::query()->whereKey($run->id)->first(['status', 'pid']);
        $sleeping = $fresh->pid === null ? [] : DB::connection('pgsql_super')->select('SELECT query FROM pg_stat_activity WHERE pid = ? AND state = ? AND wait_event = ?', [(int) $fresh->pid, 'active', 'PgSleep']);
    } while ($sleeping === [] && microtime(true) < $deadline);

    expect($fresh->status)->toBe(RunStatus::RUNNING, 'The run never started: '.$worker->getErrorOutput().$worker->getOutput())
        ->and($sleeping)->not->toBeEmpty()
        ->and($sleeping[0]->query)->toContain('pg_sleep(30)');

    $pid = (int) $fresh->pid;

    DbExplorer::roleUsing(fn (): string => 'viewer');

    $this->actingAs(makeUser(1))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson("/dbexplorer/api/main/runs/{$run->id}/cancel")
        ->assertStatus(202);

    $worker->wait();

    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput().$worker->getOutput());

    $run->refresh();

    expect($run->status)->toBe(RunStatus::CANCELLED)
        ->and($run->pid)->toBeNull()
        ->and($run->error)->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and(AuditEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['run.cancel', 'run.cancelled']);

    // The cancelled backend is gone from the server.
    $deadline = microtime(true) + 5;

    do {
        usleep(100000);
        $backends = DB::connection('pgsql_super')->select('SELECT pid FROM pg_stat_activity WHERE pid = ?', [$pid]);
    } while ($backends !== [] && microtime(true) < $deadline);

    expect($backends)->toBeEmpty();
});

it('cancels a background query or export mid-statement: the job stores its pid before the query starts', function (string $job, string $type, array $meta) {
    createQueueTables();
    createUsers(1);
    config(['dbexplorer.storage.disk' => 'local']);

    $run = createRun(['type' => $type, 'meta' => array_merge([Run::USER_PROVIDER => 'users', 'sql' => 'SELECT pg_sleep(60) AS slept'], $meta)]);

    $job::dispatch($run->id);
    $worker = startWorker();

    // Wait for the pid, then for the backend to be inside the sleep.
    $deadline = microtime(true) + 30;
    $active = [];

    do {
        usleep(100000);
        $fresh = Run::query()->whereKey($run->id)->first(['status', 'pid']);
        $active = $fresh->pid === null ? [] : DB::connection('pgsql_super')->select('SELECT pid FROM pg_stat_activity WHERE pid = ? AND state = ? AND wait_event = ?', [(int) $fresh->pid, 'active', 'PgSleep']);
    } while ($active === [] && microtime(true) < $deadline);

    expect($active)->not->toBeEmpty('The query never started sleeping with its pid stored: '.$worker->getErrorOutput().$worker->getOutput());

    $pid = (int) $fresh->pid;

    DbExplorer::roleUsing(fn (): string => 'viewer');

    $cancelledAt = microtime(true);

    $this->actingAs(makeUser(1))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson("/dbexplorer/api/main/runs/{$run->id}/cancel")
        ->assertStatus(202);

    // The statement is cancelled, not waited out: well within its 60 seconds.
    do {
        usleep(100000);
        $status = Run::query()->whereKey($run->id)->value('status');
    } while ($status !== RunStatus::CANCELLED && microtime(true) < $cancelledAt + 20);

    expect($status)->toBe(RunStatus::CANCELLED, 'Not cancelled within 20 s: '.$worker->getErrorOutput().$worker->getOutput());

    $worker->wait();

    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput().$worker->getOutput());

    $run->refresh();

    expect($run->pid)->toBeNull()
        ->and($run->error)->toBeNull()
        ->and($run->result_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('exports'))->toBe([]);

    // The cancelled backend is gone from the server with the worker.
    $deadline = microtime(true) + 5;

    do {
        usleep(100000);
        $backends = DB::connection('pgsql_super')->select('SELECT pid FROM pg_stat_activity WHERE pid = ?', [$pid]);
    } while ($backends !== [] && microtime(true) < $deadline);

    expect($backends)->toBeEmpty();
})->with([
    'background query' => [RunQuery::class, RunQuery::TYPE, []],
    'export' => [Export::class, Export::TYPE, ['format' => ExportWriter::JSONL]],
]);

it('F39 cancels a running backup: pg_dump is stopped and its partial file deleted', function () {
    createQueueTables();
    createUsers(1);
    config(['dbexplorer.backup.disk' => 'local']);

    // A pg_dump that writes part of a dump, records its pid, then hangs.
    $dir = sys_get_temp_dir().'/dbx-f39-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $fake = "{$dir}/pg_dump";
    file_put_contents($fake, "#!/bin/sh\n"
        ."if [ \"\$1\" = --version ]; then echo 'pg_dump (PostgreSQL) 99.1'; exit 0; fi\n"
        ."while [ \$# -gt 0 ]; do [ \"\$1\" = --file ] && out=\"\$2\"; shift; done\n"
        ."echo 'PGDMP partial' > \"\$out\"\n"
        ."echo \$\$ > {$dir}/pid\n"
        ."exec sleep 60\n");
    chmod($fake, 0755);

    $run = createRun(['type' => Backup::TYPE, 'role' => 'admin']);
    $file = Storage::disk('local')->path("backups/main/run-{$run->id}.dump");

    Backup::dispatch($run->id);

    $worker = new Process(
        [PHP_BINARY, __DIR__.'/scripts/work-one-job.php'],
        null,
        ['TEST_TOKEN' => getenv('TEST_TOKEN') ?: '', 'DBX_TEST_ROLE' => 'admin', 'DBX_TEST_PG_DUMP' => $fake],
        null,
        90,
    );
    $worker->start();

    try {
        // Wait until the fake pg_dump is hanging with its partial file written.
        $deadline = microtime(true) + 30;

        while (! (is_file("{$dir}/pid") && is_file($file)) && microtime(true) < $deadline) {
            usleep(100000);
        }

        expect(is_file($file))->toBeTrue('pg_dump never started: '.$worker->getErrorOutput().$worker->getOutput())
            ->and(Run::query()->whereKey($run->id)->value('status'))->toBe(RunStatus::RUNNING);

        $pid = (int) file_get_contents("{$dir}/pid");

        DbExplorer::roleUsing(fn (): string => 'admin');

        $cancelledAt = microtime(true);

        $this->actingAs(makeUser(1))
            ->withSession(['_token' => 'csrf-test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
            ->postJson("/dbexplorer/api/main/runs/{$run->id}/cancel")
            ->assertStatus(202);

        // Stopped well within the dump's 60 seconds, not waited out.
        do {
            usleep(100000);
            $status = Run::query()->whereKey($run->id)->value('status');
        } while ($status !== RunStatus::CANCELLED && microtime(true) < $cancelledAt + 20);

        expect($status)->toBe(RunStatus::CANCELLED, 'Not cancelled within 20 s: '.$worker->getErrorOutput().$worker->getOutput());

        $worker->wait();

        expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput().$worker->getOutput());

        $run->refresh();

        $alive = new Process(['kill', '-0', (string) $pid]);
        $alive->run();

        expect($run->error)->toBeNull()
            ->and($run->result_path)->toBeNull()
            ->and(is_file($file))->toBeFalse()
            ->and($alive->isSuccessful())->toBeFalse()
            ->and(AuditEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['run.cancel', 'run.cancelled']);
    } finally {
        $worker->stop(0);
        Storage::disk('local')->deleteDirectory('backups');
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
    }
});
