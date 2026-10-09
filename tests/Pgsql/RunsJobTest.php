<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Tests\Jobs\RunStatements;

beforeEach(function () {
    app()['env'] = 'local';
});

it('cancels a running query mid-statement from the API, with a real queue worker', function () {
    // The database queue this process dispatches to and the worker reads from.
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

    createUsers(1);
    config(['queue.default' => 'database']);

    $run = createRun(['meta' => [Run::USER_PROVIDER => 'users', 'statements' => ['SELECT pg_sleep(30)']]]);

    RunStatements::dispatch($run->id);

    $worker = new Process(
        [PHP_BINARY, __DIR__.'/scripts/work-one-job.php'],
        null,
        ['TEST_TOKEN' => getenv('TEST_TOKEN') ?: ''],
        null,
        90,
    );
    $worker->start();

    $deadline = microtime(true) + 30;

    do {
        usleep(100000);
        $fresh = Run::query()->whereKey($run->id)->first(['status', 'pid']);
    } while (($fresh->status !== RunStatus::RUNNING || $fresh->pid === null) && microtime(true) < $deadline);

    expect($fresh->status)->toBe(RunStatus::RUNNING, 'The run never started: '.$worker->getErrorOutput().$worker->getOutput());

    $pid = (int) $fresh->pid;

    // The worker is really inside the sleep, not between statements.
    $sleeping = DB::connection('pgsql_super')->select('SELECT query FROM pg_stat_activity WHERE pid = ? AND state = ?', [$pid, 'active']);

    expect($sleeping)->not->toBeEmpty()
        ->and($sleeping[0]->query)->toContain('pg_sleep(30)');

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
