<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use TacticalNinj4\DbExplorer\Events\DdlExecuted;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\RunScript;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;

// M3.5: the script runner's job, against the fixture Postgres.

beforeEach(function () {
    app()['env'] = 'local';

    $this->schema = $this->scratchSchema();
    createUsers(1);
});

/**
 * A queued `script` run for user 1 on `main`, running $sql in $mode.
 *
 * @param  array<string, mixed>  $extra
 */
function scriptRun(string $sql, string $role = 'admin', string $mode = RunScript::TRANSACTION, array $extra = []): Run
{
    return createRun([
        'type' => RunScript::TYPE,
        'role' => $role,
        'meta' => [Run::USER_PROVIDER => 'users', 'sql' => $sql, 'mode' => $mode],
    ] + $extra);
}

function runScriptJob(Run $run): void
{
    app()->call([new RunScript($run->id), 'handle']);
}

it('rolls the whole script back when one statement fails in transaction mode', function () {
    Event::fake([DdlExecuted::class]);
    DbExplorer::roleUsing(fn (): string => 'admin');

    $run = scriptRun("CREATE TABLE {$this->schema}.t (a int);\nINSERT INTO {$this->schema}.t VALUES (1);\nSELECT 1/0");
    runScriptJob($run);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toContain('22012')
        ->and(array_column($run->meta['statements'], 'status'))->toBe(['rolled_back', 'rolled_back', 'failed'])
        ->and($run->meta['statements'][0]['duration_ms'])->toBeFloat()
        ->and($run->meta['failed_statement'])->toBe(2)
        ->and(DB::connection('pgsql_super')->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', ["{$this->schema}.t"])->present)->toBeFalse();

    $audit = AuditEntry::query()->where('action', 'script')->sole();

    expect($audit->outcome)->toBe('error')
        ->and($audit->meta)->toBe(['failed_statement' => 2, 'mode' => 'transaction', 'run_id' => $run->id, 'statements' => 3])
        ->and($audit->sql)->toContain("CREATE TABLE {$this->schema}.t")
        ->and(AuditEntry::query()->where('action', 'run.failed')->exists())->toBeTrue();

    Event::assertNotDispatched(DdlExecuted::class); // rolled-back DDL never happened
});

it('keeps what already committed when a statement fails in step mode, and fires DdlExecuted for it', function () {
    Event::fake([DdlExecuted::class]);
    DbExplorer::roleUsing(fn (): string => 'admin');

    $create = "CREATE TABLE {$this->schema}.t (a int)";
    $run = scriptRun($create.";\nSELECT 1/0;\nCREATE TABLE {$this->schema}.u (a int)", 'admin', RunScript::STEP);
    runScriptJob($run);

    $super = DB::connection('pgsql_super');

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($super->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', ["{$this->schema}.t"])->present)->toBeTrue()
        ->and($super->selectOne('SELECT to_regclass(?) IS NOT NULL AS present', ["{$this->schema}.u"])->present)->toBeFalse()
        ->and(array_column($run->meta['statements'], 'status'))->toBe(['succeeded', 'failed', 'skipped'])
        ->and($run->meta['statements'][0]['duration_ms'])->toBeFloat();

    Event::assertDispatchedTimes(DdlExecuted::class, 1);

    $event = Event::dispatched(DdlExecuted::class)->first()[0];

    expect($event->sql)->toBe($create)
        ->and($event->role)->toBe('admin');
});

it('runs a committed script to the end and exposes per-statement outcomes through the runs API', function () {
    DbExplorer::roleUsing(fn (): string => 'editor');
    DB::connection('pgsql_admin')->statement("CREATE TABLE {$this->schema}.t (a text)");

    $run = scriptRun("INSERT INTO {$this->schema}.t VALUES ('sentinel-value');\nINSERT INTO {$this->schema}.t VALUES (2)", 'editor', RunScript::STEP);
    runScriptJob($run);

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and($run->progress)->toBe(100)
        ->and(array_column($run->meta['statements'], 'affected'))->toBe([1, 1]);

    $audit = AuditEntry::query()->where('action', 'script')->sole();

    expect($audit->outcome)->toBe('success')
        ->and($audit->row_count)->toBe(2)
        ->and($audit->classification)->toBe('dml');

    DbExplorer::roleUsing(fn (): string => 'editor');

    $response = $this->actingAs(makeUser(1))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->getJson("/dbexplorer/api/main/runs/{$run->id}")
        ->assertOk();

    $statements = $response->json('data.statements');

    expect($statements)->toHaveCount(2)
        ->and(array_keys($statements[0]))->toBe(['line', 'status', 'duration_ms', 'affected'])
        ->and($statements[0]['line'])->toBe(1)
        ->and($statements[0]['status'])->toBe('succeeded')
        ->and($statements[0]['affected'])->toBe(1)
        ->and($statements[1]['line'])->toBe(2)
        ->and((string) $response->getContent())->not->toContain('sentinel-value');
});

it('fails at the database when an editor role run meets DDL, whatever the gate said at submit time', function () {
    DbExplorer::roleUsing(fn (): string => 'editor');

    $run = scriptRun("CREATE TABLE {$this->schema}.evil (a int)", 'editor', RunScript::STEP);
    runScriptJob($run);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->error)->toContain('42501')
        ->and($run->meta['statements'][0]['status'])->toBe('failed');
});

it('cancels between statements through a real queue worker', function () {
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

    DbExplorer::roleUsing(fn (): string => 'viewer');

    // The worker process resolves roles on its own: viewer is the default.
    $run = scriptRun(implode(";\n", array_fill(0, 4, 'SELECT pg_sleep(1)')), 'viewer', RunScript::STEP);

    RunScript::dispatch($run->id);

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
        $fresh = Run::query()->whereKey($run->id)->first(['status']);
    } while ($fresh->status !== RunStatus::RUNNING && microtime(true) < $deadline);

    expect($fresh->status)->toBe(RunStatus::RUNNING, 'The run never started: '.$worker->getErrorOutput().$worker->getOutput());

    $this->actingAs(makeUser(1))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson("/dbexplorer/api/main/runs/{$run->id}/cancel")
        ->assertStatus(202);

    $worker->wait();

    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput().$worker->getOutput());

    $run->refresh();
    $statuses = array_column($run->meta['statements'], 'status');

    expect($run->status)->toBe(RunStatus::CANCELLED)
        ->and($statuses)->toHaveCount(4)
        ->and(in_array('skipped', $statuses, true))->toBeTrue()
        ->and(end($statuses))->toBe('skipped')
        ->and(AuditEntry::query()->where('action', 'script')->value('outcome'))->toBe('cancelled')
        ->and(AuditEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['run.cancel', 'script', 'run.cancelled']);
});
