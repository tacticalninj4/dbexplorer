<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use TacticalNinj4\DbExplorer\Models\QueryHistory;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;

// M3.3: dbx:prune now deletes expired result files, finished runs and query
// history next to the audit log (whose checkpoint behaviour AuditTest covers).

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly'],
    ]]);

    $this->artisan('migrate');

    Storage::fake('local');
    config(['dbexplorer.storage.disk' => 'local', 'dbexplorer.storage.file_ttl_hours' => 24]);
});

it('deletes only expired result files', function () {
    $disk = Storage::disk('local');

    $disk->put('exports/fresh.csv', 'new');
    $disk->put('exports/stale.csv', 'old');
    $disk->put('exports/stale-too.jsonl', 'old too');

    touch($disk->path('exports/stale.csv'), time() - 25 * 3600);
    touch($disk->path('exports/stale-too.jsonl'), time() - 25 * 3600);

    Artisan::call('dbx:prune');

    expect($disk->exists('exports/fresh.csv'))->toBeTrue()
        ->and($disk->exists('exports/stale.csv'))->toBeFalse()
        ->and($disk->exists('exports/stale-too.jsonl'))->toBeFalse()
        ->and(Artisan::output())->toContain('pruned 2 expired result files');
});

it('keeps result files when file retention is disabled', function () {
    config(['dbexplorer.storage.file_ttl_hours' => 0]);

    Storage::disk('local')->put('exports/stale.csv', 'old');

    Artisan::call('dbx:prune');

    expect(Storage::disk('local')->exists('exports/stale.csv'))->toBeTrue()
        ->and(Artisan::output())->toContain('Files: retention disabled');
});

it('deletes finished runs past the retention, never active ones', function () {
    config(['dbexplorer.audit.retention_days' => 30]);

    $old = createRun(['status' => RunStatus::SUCCEEDED]);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();

    $queued = createRun();
    $queued->forceFill(['created_at' => now()->subDays(400)])->save();

    Artisan::call('dbx:prune');

    expect(Run::query()->pluck('id')->all())->toBe([$queued->id])
        ->and(Artisan::output())->toContain('pruned 1 finished run');
});

it('deletes query history past the retention', function () {
    config(['dbexplorer.audit.retention_days' => 30]);

    QueryHistory::query()->insert([
        ['user_id' => '1', 'label' => 'main', 'role' => 'viewer', 'sql' => 'SELECT 1', 'classification' => 'read', 'duration_ms' => 1, 'row_count' => 1, 'outcome' => 'success', 'error' => null, 'created_at' => now()->subDays(31)->format('Y-m-d H:i:s')],
        ['user_id' => '1', 'label' => 'main', 'role' => 'viewer', 'sql' => 'SELECT 2', 'classification' => 'read', 'duration_ms' => 1, 'row_count' => 1, 'outcome' => 'success', 'error' => null, 'created_at' => now()->format('Y-m-d H:i:s')],
    ]);

    Artisan::call('dbx:prune');

    expect(QueryHistory::query()->pluck('sql')->all())->toBe(['SELECT 2'])
        ->and(Artisan::output())->toContain('History: pruned 1 history row');
});

it('keeps everything when retention is disabled', function () {
    config(['dbexplorer.audit.retention_days' => 0]);

    $old = createRun(['status' => RunStatus::FAILED]);
    $old->forceFill(['created_at' => now()->subDays(400)])->save();

    Artisan::call('dbx:prune');

    expect(Run::query()->count())->toBe(1)
        ->and(Artisan::output())->toContain('Runs: retention disabled');
});
