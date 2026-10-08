<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;

it('S22 does not fork the chain when two processes write concurrently', function () {
    $perWriter = 100;
    $startAt = (string) (microtime(true) + 3);

    $writers = [];

    foreach (['writer-a', 'writer-b'] as $name) {
        $writers[$name] = new Process(
            [PHP_BINARY, __DIR__.'/scripts/write-audit-entries.php', $name, (string) $perWriter, $startAt],
            null,
            ['TEST_TOKEN' => getenv('TEST_TOKEN') ?: ''],
            null,
            60,
        );
        $writers[$name]->start();
    }

    foreach ($writers as $name => $process) {
        $process->wait();

        expect($process->getExitCode())->toBe(0, "{$name} failed: ".$process->getErrorOutput().$process->getOutput());
    }

    $rows = DB::table('dbx_audit_log')->orderBy('id')->get(['label', 'prev_hash']);

    // The writers really interleaved, so the lock was exercised.
    $switches = 0;
    foreach ($rows->slice(1) as $i => $row) {
        $switches += $row->label !== $rows[$i - 1]->label ? 1 : 0;
    }

    expect($rows)->toHaveCount(2 * $perWriter)
        ->and($rows->pluck('prev_hash')->unique())->toHaveCount(2 * $perWriter)
        ->and($switches)->toBeGreaterThan(1)
        ->and(Artisan::call('dbx:audit:verify'))->toBe(0, Artisan::output());
});

it('S22 verifies a chain stored in PostgreSQL', function () {
    foreach (range(1, 3) as $i) {
        DbExplorer::audit([
            'action' => 'query',
            'user_id' => $i,
            'sql' => "select 'ünïcødé', {$i}",
            'duration_ms' => 1.5,
            'meta' => ['b' => [1, 2], 'a' => null],
        ]);
    }

    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(Artisan::call('dbx:audit:verify'))->toBe(0);

    DB::table('dbx_audit_log')->where('id', 2)->update(['sql' => 'select 0']);

    expect(Artisan::call('dbx:audit:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('entry #2');
});
