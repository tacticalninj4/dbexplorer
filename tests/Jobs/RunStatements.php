<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Tests\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Exceptions\RunCancelled;
use TacticalNinj4\DbExplorer\Jobs\Concerns\TracksRun;
use TacticalNinj4\DbExplorer\Models\Run;

/**
 * Test-only job for TracksRun: runs the run's `meta.statements` in one
 * transaction through QueryRunner::atomic(), with `meta.bindings` bound to
 * each, recording progress and checking for a cancel between statements.
 */
final class RunStatements implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksRun;

    public const TYPE = 'test_statements';

    protected function process(Run $run, string $connection): void
    {
        $statements = $run->meta['statements'] ?? [];
        $statements = is_array($statements) ? array_values(array_filter($statements, 'is_string')) : [];
        $bindings = $run->meta['bindings'] ?? [];
        /** @var list<scalar|null> $bindings */
        $bindings = is_array($bindings) ? array_values($bindings) : [];

        app(QueryRunner::class)->atomic($connection, (string) $run->role, function (Closure $statement) use ($statements, $bindings): void {
            $this->trackBackend($statement);

            foreach ($statements as $i => $sql) {
                if ($this->shouldStop()) {
                    throw new RunCancelled;
                }

                $statement($sql, substr_count($sql, '?') > 0 ? $bindings : []);
                $this->progress((int) floor(($i + 1) * 100 / count($statements)), ['done' => $i + 1]);
            }
        });
    }
}
