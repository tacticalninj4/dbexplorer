<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use TacticalNinj4\DbExplorer\Export\ExportWriter;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\Export;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;

// M3.2: the export job, streaming against the fixture Postgres.

beforeEach(function () {
    app()['env'] = 'local';

    $this->schema = $this->scratchSchema();
    createUsers(1);

    config(['dbexplorer.storage.disk' => 'local']);
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('exports');
});

/**
 * A queued export run for user 1 on `main` and, unless $run is false, the
 * job handled right away (the same thing a worker does).
 *
 * @param  array<string, mixed>  $meta
 */
function exportRun(array $meta, string $role = 'viewer', bool $run = true): Run
{
    $model = createRun([
        'type' => Export::TYPE,
        'role' => $role,
        'meta' => array_merge([Run::USER_PROVIDER => 'users', 'format' => ExportWriter::CSV], $meta),
    ]);

    if ($run) {
        app()->call([new Export($model->id), 'handle']);
        $model->refresh();
    }

    return $model;
}

function exportContents(Run $run): string
{
    return (string) Storage::disk('local')->get((string) $run->result_path);
}

it('streams a query to csv with masked columns masked (S14, D4)', function () {
    DbExplorer::roleUsing(fn (): string => 'viewer');

    $run = exportRun(['sql' => 'SELECT id, name, password, api_token FROM fixture.users ORDER BY id']);

    $count = DB::connection('pgsql_super')->table('fixture.users')->count();

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and($run->result_path)->toBe('exports/run-'.$run->id.'.csv')
        ->and($run->meta['rows'])->toBe($count);

    $contents = exportContents($run);
    $lines = array_values(array_filter(explode("\n", $contents), fn (string $line): bool => $line !== ''));

    expect(str_getcsv((string) $lines[0]))->toBe(['id', 'name', 'password', 'api_token'])
        ->and(str_getcsv((string) $lines[1]))->toBe(['1', 'Alice', '••••••', '••••••'])
        ->and($contents)->not->toContain('aliceHash')
        ->not->toContain('tok_alice');
});

it('streams json lines that round-trip', function () {
    DbExplorer::roleUsing(fn (): string => 'viewer');

    $run = exportRun(['sql' => 'SELECT id, name FROM fixture.users ORDER BY id', 'format' => ExportWriter::JSONL]);

    $ids = DB::connection('pgsql_super')->table('fixture.users')->orderBy('id')->pluck('id')->all();
    $lines = array_values(array_filter(explode("\n", exportContents($run)), fn (string $line): bool => $line !== ''));

    expect(count($lines))->toBe(count($ids))
        ->and(json_decode((string) $lines[0], true))->toBe([(int) $ids[0], 'Alice'])
        ->and(json_decode((string) $lines[count($lines) - 1], true))->toBe([(int) end($ids), DB::connection('pgsql_super')->table('fixture.users')->orderByDesc('id')->value('name')]);
});

it('streams sql INSERTs that restore the rows they came from', function () {
    DbExplorer::roleUsing(fn (): string => 'admin');

    // Owned by dbx_admin, so the admin role the export runs as can read it.
    $super = DB::connection('pgsql_admin');
    $super->statement("CREATE TABLE {$this->schema}.t (label text NOT NULL, n int NOT NULL)");
    $super->unprepared("INSERT INTO {$this->schema}.t VALUES ('O''Brien and \"Co\"', 1), ('ünïcødé — ✓', 2)");

    $run = exportRun([
        'sql' => "SELECT label, n FROM {$this->schema}.t ORDER BY n",
        'format' => ExportWriter::SQL,
        'table' => ['schema' => $this->schema, 'name' => 't'],
    ], 'admin');

    $sql = exportContents($run);

    expect($run->status)->toBe(RunStatus::SUCCEEDED)
        ->and($sql)->toContain("INSERT INTO \"{$this->schema}\".\"t\" (\"label\", \"n\") VALUES");

    // The dump restores the exact rows, quotes and unicode included.
    $super->statement("DELETE FROM {$this->schema}.t");
    $super->unprepared($sql);

    expect($super->table("{$this->schema}.t")->count())->toBe(2)
        ->and($super->table("{$this->schema}.t")->where('n', 1)->value('label'))->toBe('O\'Brien and "Co"')
        ->and($super->table("{$this->schema}.t")->where('n', 2)->value('label'))->toBe('ünïcødé — ✓');
});

it('keeps memory flat while exporting the million-row table', function () {
    // A fresh PHP process runs the job, so the peak is the export's own, not
    // what this test process retained from earlier tests.
    $run = exportRun(['sql' => 'SELECT * FROM fixture.big', 'format' => ExportWriter::JSONL], 'viewer', false);

    $worker = new Process(
        [PHP_BINARY, __DIR__.'/scripts/run-export-job.php', (string) $run->id],
        null,
        ['TEST_TOKEN' => getenv('TEST_TOKEN') ?: ''],
        null,
        120,
    );
    $worker->run();

    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput().$worker->getOutput());

    $peakKb = (int) (preg_match('/DBX-PEAK-KB: (\d+)/', $worker->getOutput(), $m) ? $m[1] : 0);

    expect($run->refresh()->status)->toBe(RunStatus::SUCCEEDED)
        ->and($run->meta['rows'])->toBe(1000000)
        ->and(Storage::disk('local')->size((string) $run->result_path))->toBeGreaterThan(20000000)
        ->and($peakKb)->toBeGreaterThan(0)
        ->and($peakKb)->toBeLessThan(64 * 1024, 'peak memory was '.round($peakKb / 1024, 1).' MB');
});

it('fails the run when the query reads a hidden object, storing no file', function () {
    config(['dbexplorer.hidden' => ['fixture.secrets']]);

    $run = exportRun(['sql' => 'SELECT * FROM fixture.secrets']);

    expect($run->refresh()->status)->toBe(RunStatus::FAILED)
        ->and($run->result_path)->toBeNull()
        ->and($run->error)->not->toBeNull()
        ->and(Storage::disk('local')->exists('exports'))->toBeFalse();
});

it('cancels a running export through a real queue worker, storing no file', function () {
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

    $run = exportRun(['sql' => 'SELECT * FROM fixture.big', 'format' => ExportWriter::JSONL], 'viewer', false);

    Export::dispatch($run->id);

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

    expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput().$worker->getOutput())
        ->and($run->refresh()->status)->toBe(RunStatus::CANCELLED)
        ->and($run->result_path)->toBeNull()
        ->and($run->toApi()['result_available'])->toBeFalse()
        ->and(Storage::disk('local')->exists('exports/run-'.$run->id.'.jsonl'))->toBeFalse();
});

it('queues a table export for a viewer (D4), with the query only in the run row', function () {
    Queue::fake();
    DbExplorer::roleUsing(fn (): string => 'viewer');

    $run = $this->actingAs(makeUser(1))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/export', ['table' => ['schema' => 'fixture', 'name' => 'users']])
        ->assertStatus(202)
        ->json('data');

    expect($run['type'])->toBe(Export::TYPE)
        ->and($run['status'])->toBe(RunStatus::QUEUED);

    $model = Run::query()->sole();

    expect($model->only(['user_id', 'label', 'role']))->toBe(['user_id' => '1', 'label' => 'main', 'role' => 'viewer'])
        ->and($model->meta['sql'])->toStartWith('SELECT "id", "email", ')->toEndWith(' FROM "fixture"."users"')
        ->and($model->meta['format'])->toBe(ExportWriter::CSV)
        ->and($model->meta['table'])->toBe(['schema' => 'fixture', 'name' => 'users']);

    Queue::assertPushed(Export::class, function (Export $job) use ($model): bool {
        return $job->runId === $model->id
            && ! str_contains(serialize($job), 'fixture')
            && ! str_contains(serialize($job), 'pgsql');
    });
});

it('S12 S13 answers a missing or hidden table export with the same 404, queueing nothing', function (string $name) {
    Queue::fake();
    config(['dbexplorer.hidden' => ['fixture.secrets']]);
    DbExplorer::roleUsing(fn (): string => 'viewer');

    $this->actingAs(makeUser(1))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/export', ['table' => ['schema' => 'fixture', 'name' => $name], 'format' => ExportWriter::SQL])
        ->assertStatus(404)
        ->assertExactJson(['message' => 'The database object does not exist.']);

    expect(Run::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['missing' => ['nope'], 'hidden' => ['secrets'], 'injected' => ['users"; DROP TABLE x; --']]);

it('S12 quotes table and column names in sql exports: hostile identifiers restore exactly the rows and nothing else', function () {
    DbExplorer::roleUsing(fn (): string => 'admin');

    $table = "we\"ird;\n-- t";
    $quoted = '"'.str_replace('"', '""', $table).'"';
    $admin = DB::connection('pgsql_admin');

    $admin->unprepared(<<<SQL
        CREATE TABLE {$this->schema}.{$quoted} ("a""b" text, "c;d" int, "e
        f" text, "--g" text);
        CREATE TABLE {$this->schema}.sentinel (n int);
        INSERT INTO {$this->schema}.sentinel VALUES (1);
        SQL);
    $admin->table("{$this->schema}.{$table}")->insert([
        ['a"b' => "x'); DROP TABLE {$this->schema}.sentinel; --", 'c;d' => 1, "e\nf" => "line\nbreak", '--g' => '"; --'],
        ['a"b' => null, 'c;d' => 2, "e\nf" => '', '--g' => 'plain'],
    ]);

    $this->actingAs(makeUser(1))
        ->withSession(['_token' => 'csrf-test-token'])
        ->withHeaders(['X-CSRF-TOKEN' => 'csrf-test-token'])
        ->postJson('/dbexplorer/api/main/export', ['table' => ['schema' => $this->schema, 'name' => $table], 'format' => ExportWriter::SQL])
        ->assertStatus(202);

    $run = Run::query()->sole();
    $before = $admin->table("{$this->schema}.{$table}")->orderBy('c;d')->get()->map(fn (object $row): array => (array) $row)->all();

    expect($run->status)->toBe(RunStatus::SUCCEEDED, (string) $run->error);

    $sql = exportContents($run);

    expect(substr_count($sql, "INSERT INTO \"{$this->schema}\".{$quoted} (\"a\"\"b\", \"c;d\", \"e\nf\", \"--g\") VALUES"))->toBe(2);

    // Restored into the emptied table, the file recreates exactly the rows,
    // and nothing else in the schema changes.
    $admin->statement("DELETE FROM {$this->schema}.{$quoted}");
    $admin->unprepared($sql);

    $after = $admin->table("{$this->schema}.{$table}")->orderBy('c;d')->get()->map(fn (object $row): array => (array) $row)->all();
    $relations = $admin->select('SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? ORDER BY 1', [$this->schema]);

    expect($after)->toBe($before)
        ->and(array_column(array_map(fn (object $row): array => (array) $row, $relations), 'relname'))->toBe(['sentinel', $table])
        ->and($admin->table("{$this->schema}.sentinel")->pluck('n')->all())->toBe([1]);
});
