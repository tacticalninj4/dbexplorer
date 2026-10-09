<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use PDOException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Events\DdlExecuted;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Exceptions\RunCancelled;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\Concerns\TracksRun;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Sql\Classifier;
use TacticalNinj4\DbExplorer\Sql\ScriptSplitter;

/**
 * The script runner's job (PRD §7.7): runs the run's `meta.sql` — already
 * split for the run, or re-split here — statement by statement.
 *
 * Two modes, the run's `meta.mode`:
 * - `transaction` (default): one transaction around every statement, so an
 *   error rolls back everything the script had done;
 * - `step`: every statement in its own transaction, committing as it goes.
 *   The run stops on the first error, keeping what already committed. This is
 *   where `CREATE INDEX CONCURRENTLY` runs, which no transaction can hold.
 *
 * Each statement's outcome lands in `meta.statements` as
 * `{line, status, duration_ms, affected}` — status `succeeded`, `failed`,
 * `skipped` (never reached), `rolled_back` (had succeeded before a
 * transaction-mode failure undid it) or `cancelled` (killed mid-statement by
 * a cancel request) — never the SQL text: the run row keeps that once, in
 * `meta.sql`, and the audit entry carries it for real.
 *
 * The job ends by rethrowing its failure so TracksRun records the run itself:
 * RunCancelled cancels it, a cancelled query (57014) after a cancel request
 * cancels it, any other QueryFailed fails it with the audit-style error.
 *
 * One audit entry (action `script`) covers the whole script with per-statement
 * counts; {@see DdlExecuted} fires for the statements that committed as DDL.
 */
final class RunScript implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksRun;

    public const TYPE = 'script';

    public const TRANSACTION = 'transaction';

    public const STEP = 'step';

    protected function process(Run $run, string $connection): void
    {
        $sql = (string) ($run->meta['sql'] ?? '');
        $mode = ($run->meta['mode'] ?? null) === self::STEP ? self::STEP : self::TRANSACTION;
        $role = (string) $run->role;

        $statements = ScriptSplitter::split($sql);

        if ($statements === []) {
            throw new QueryFailed('There is no statement to run.', '42601');
        }

        /** @var list<array{line: int, status: string, duration_ms: float|null, affected: int|null}> $outcomes */
        $outcomes = [];
        $failed = null;
        $failure = null;
        $cancelled = false;
        $started = hrtime(true);

        // Runs one statement through $exec and records its outcome. A database
        // failure is a PDOException here: atomic()/autoCommit() turn it into
        // a QueryFailed once the callback has unwound.
        $execute = function (Closure $exec, int $index, array $statement) use (&$outcomes): void {
            $at = hrtime(true);

            try {
                $result = $exec((string) $statement['sql'], []);
            } catch (PDOException $exception) {
                $outcomes[$index] = ['line' => (int) $statement['line'], 'status' => 'failed', 'duration_ms' => round((hrtime(true) - $at) / 1e6, 3), 'affected' => null];

                throw $exception;
            }

            $outcomes[$index] = ['line' => (int) $statement['line'], 'status' => 'succeeded', 'duration_ms' => round((hrtime(true) - $at) / 1e6, 3), 'affected' => $result['affected']];
        };

        $runner = app(QueryRunner::class);

        try {
            if ($mode === self::STEP) {
                foreach ($statements as $index => $statement) {
                    if ($this->shouldStop()) {
                        $cancelled = true;

                        break;
                    }

                    // Each statement auto-commits: CONCURRENTLY refuses any
                    // transaction block, and an error keeps what already ran.
                    $runner->autoCommit($connection, $role, function (Closure $exec) use ($execute, $index, $statement): void {
                        $this->trackBackend($exec, false);
                        $execute($exec, $index, $statement);
                    });

                    $this->progress($this->percent($index, count($statements)), ['statements' => $outcomes]);
                }
            } else {
                try {
                    $runner->atomic($connection, $role, function (Closure $exec) use ($statements, $execute, &$outcomes): void {
                        $this->trackBackend($exec);

                        foreach ($statements as $index => $statement) {
                            if ($this->shouldStop()) {
                                throw new RunCancelled;
                            }

                            $execute($exec, $index, $statement);

                            $this->progress($this->percent($index, count($statements)), ['statements' => $outcomes]);
                        }
                    });
                } catch (QueryFailed $exception) {
                    // The rollback undid every earlier statement, succeeded or not.
                    foreach ($outcomes as $index => $outcome) {
                        if ($outcome['status'] === 'succeeded') {
                            $outcomes[$index]['status'] = 'rolled_back';
                        }
                    }

                    throw $exception;
                }
            }
        } catch (RunCancelled) {
            $cancelled = true;
        } catch (QueryFailed $exception) {
            // pg_cancel_backend() killed the running statement after the cancel
            // request: the run is cancelled, not failed.
            $cancelled = $exception->sqlState === '57014' && $this->shouldStop();

            if (! $cancelled) {
                $failure = $exception;
            }
        }

        foreach ($statements as $index => $statement) {
            $outcomes[$index] ??= ['line' => (int) $statement['line'], 'status' => 'skipped', 'duration_ms' => null, 'affected' => null];
        }

        // The one statement the run stopped at, if any: the first that didn't
        // succeed or get skipped. A cancel request rewrites it as cancelled.
        $failed = null;

        foreach ($outcomes as $index => $outcome) {
            if ($outcome['status'] === 'failed') {
                $failed = $index;

                if ($cancelled) {
                    $outcomes[$index]['status'] = 'cancelled';
                }

                break;
            }
        }

        $final = ['statements' => $outcomes];

        if ($failed !== null) {
            $final['failed_statement'] = $failed;
        }

        $this->progress(100, $final);

        $this->recordScript($run, $sql, $mode, $statements, $outcomes, $failed, $cancelled, $failure, (hrtime(true) - $started) / 1e6);

        if ($cancelled) {
            throw new RunCancelled;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * The whole-script audit entry (sql, classification, outcome, per-statement
     * counts) and DdlExecuted for the statements that committed as DDL. Only
     * `succeeded` statements count: rolled-back DDL never happened.
     *
     * @param  list<array{sql: string, offset: int, line: int}>  $statements
     * @param  list<array{line: int, status: string, duration_ms: float|null, affected: int|null}>  $outcomes
     */
    private function recordScript(Run $run, string $sql, string $mode, array $statements, array $outcomes, ?int $failed, bool $cancelled, ?QueryFailed $failure, float $durationMs): void
    {
        $audit = DbExplorer::audit([
            'action' => 'script',
            'user_id' => $run->user_id,
            'label' => $run->label,
            'role' => $run->role,
            'sql' => $sql,
            'classification' => Classifier::classify($sql),
            'duration_ms' => round($durationMs, 3),
            'row_count' => array_sum(array_filter(array_column($outcomes, 'affected'), 'is_int')),
            'outcome' => $failure === null ? ($cancelled ? 'cancelled' : 'success') : 'error',
            'error' => $failure === null ? null : QueryRunner::auditError($failure, []),
            'meta' => array_filter([
                'run_id' => $run->id,
                'mode' => $mode,
                'statements' => count($statements),
                'failed_statement' => $failed,
            ], fn (mixed $value): bool => $value !== null),
        ]);

        $ddl = [];

        foreach ($statements as $index => $statement) {
            if (($outcomes[$index]['status'] ?? '') === 'succeeded' && Classifier::isDdl((string) $statement['sql'])) {
                $ddl[] = (string) $statement['sql'];
            }
        }

        if ($ddl !== []) {
            event(new DdlExecuted($audit->id, $run->user_id, $run->label, (string) $run->role, Classifier::classify(implode(";\n", $ddl)), implode(";\n", $ddl)));
        }
    }

    private function percent(int $index, int $total): int
    {
        return (int) floor(($index + 1) * 100 / max(1, $total));
    }
}
