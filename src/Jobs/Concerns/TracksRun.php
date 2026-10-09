<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Jobs\Concerns;

use Closure;
use Illuminate\Queue\MaxAttemptsExceededException;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Exceptions\ConnectionNotAllowedException;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Exceptions\RunCancelled;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Role;
use Throwable;

/**
 * The lifecycle of a job that does the work of a {@see Run} (PRD §7.5).
 *
 * The job's payload is the run id and nothing else (S21). When it runs:
 *
 * 1. A run that is gone, no longer queued, or cancelled while queued is skipped.
 * 2. The user is loaded again through the auth provider they signed in with
 *    and their role on the run's label resolved again. A user who is gone, or
 *    whose role is now lower than the run's, fails the run ("permission
 *    changed"); otherwise the run's role picks the Laravel connection.
 * 3. The run is marked running and process() does the work. Jobs call
 *    trackBackend() first thing inside their transaction, then shouldStop()
 *    between chunks or statements, throwing RunCancelled when it says so.
 * 4. Returning succeeds the run. RunCancelled, or a query cancelled (57014)
 *    after a cancel request, cancels it. Any other QueryFailed fails it with
 *    the audit-style error: SQLSTATE and first line, `meta.bindings` values
 *    redacted. Anything else fails it with a generic error and is rethrown,
 *    so the queue records and reports it.
 *
 * Jobs are never retried ($tries = 1): writes aren't idempotent. A worker
 * timeout (limits.background.timeout) fails the run through failed().
 */
trait TracksRun
{
    /** The id of the run this job does the work of. The only data in the payload. */
    public int $runId;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
        $this->timeout = max(1, (int) config('dbexplorer.limits.background.timeout', 3600));

        $connection = config('dbexplorer.queue.connection');
        $queue = config('dbexplorer.queue.queue');

        $this->onConnection(is_string($connection) && $connection !== '' ? $connection : null);
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : null);
    }

    /**
     * Do the run's work on the Laravel connection for the run's role.
     */
    abstract protected function process(Run $run, string $connection): void;

    public function handle(DbExplorer $explorer): void
    {
        $run = Run::query()->find($this->runId);

        if (! $run instanceof Run || $run->status !== RunStatus::QUEUED) {
            return;
        }

        if ($run->cancel_requested) {
            $run->finish(RunStatus::CANCELLED);

            return;
        }

        $connection = $this->authorizeRun($run, $explorer);

        if ($connection === null || ! $run->markRunning()) {
            return;
        }

        try {
            $this->process($run, $connection);
        } catch (RunCancelled) {
            $run->finish(RunStatus::CANCELLED);

            return;
        } catch (QueryFailed $e) {
            $cancelled = $e->sqlState === '57014' && $this->shouldStop();

            $run->finish(
                $cancelled ? RunStatus::CANCELLED : RunStatus::FAILED,
                $cancelled ? null : QueryRunner::auditError($e, $this->redactedValues($run)),
            );

            return;
        } catch (Throwable $e) {
            $run->finish(RunStatus::FAILED, $this->errorFor($e));

            throw $e;
        }

        $run->finish(RunStatus::SUCCEEDED);
    }

    /**
     * Called by the queue when the job fails outside handle()'s own handling,
     * e.g. on a worker timeout. Fails the run if it is still active.
     */
    public function failed(?Throwable $e = null): void
    {
        $run = Run::query()->find($this->runId);

        if ($run instanceof Run && ! $run->isFinished()) {
            $run->finish(RunStatus::FAILED, $e === null ? 'The run failed.' : $this->errorFor($e));
        }
    }

    /**
     * Whether the run's cancellation was requested. Check it between chunks
     * or statements and throw RunCancelled when it is true.
     */
    protected function shouldStop(): bool
    {
        return (bool) Run::query()->whereKey($this->runId)->value('cancel_requested');
    }

    /**
     * Call first inside the job's transaction (QueryRunner::atomic()) with its
     * `$statement`: stores the session's `pg_backend_pid()` so a cancel can
     * reach it, raises the statement timeout to limits.background.timeout, and
     * stops if a cancel arrived before the pid was stored.
     *
     * @param  Closure(string, array<int, scalar|null>): array{columns: list<string>, rows: list<list<mixed>>, affected: int}  $statement
     *
     * @throws RunCancelled
     */
    protected function trackBackend(Closure $statement): void
    {
        $pid = $statement('SELECT pg_backend_pid()', [])['rows'][0][0] ?? null;

        Run::query()->whereKey($this->runId)->update(['pid' => is_numeric($pid) ? (int) $pid : null]);

        $statement("SELECT set_config('statement_timeout', ?, true)", [(string) ($this->timeout * 1000)]);

        if ($this->shouldStop()) {
            throw new RunCancelled;
        }
    }

    /**
     * Record progress, 0 to 100, and optionally merge keys into the run's meta.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function progress(int $percent, array $meta = []): void
    {
        $changes = ['progress' => max(0, min(100, $percent))];

        if ($meta !== []) {
            $current = Run::query()->whereKey($this->runId)->value('meta');
            $current = is_string($current) ? json_decode($current, true) : $current;
            $changes['meta'] = json_encode(array_merge(is_array($current) ? $current : [], $meta));
        }

        Run::query()->whereKey($this->runId)->update($changes);
    }

    /**
     * The Laravel connection for the run, or null after failing the run
     * because its user or their role is gone or lower than when it was queued.
     */
    private function authorizeRun(Run $run, DbExplorer $explorer): ?string
    {
        $user = $run->owner();
        $role = $user === null || $run->label === null ? null : $explorer->roleFor($user, $run->label);

        if ($role === null || $run->role === null || ! Role::atLeast($role, $run->role)) {
            $run->finish(RunStatus::FAILED, 'Permission changed: you no longer have the role this run was started with.');

            return null;
        }

        try {
            return $explorer->connectionFor((string) $run->label, $run->role);
        } catch (ConnectionNotAllowedException) {
            $run->finish(RunStatus::FAILED, 'Permission changed: the connection is no longer available for this role.');

            return null;
        }
    }

    /**
     * Values to redact from error messages: the run's `meta.bindings`.
     *
     * @return array<int|string, scalar|null>
     */
    private function redactedValues(Run $run): array
    {
        $bindings = $run->meta['bindings'] ?? [];

        return is_array($bindings) ? array_filter($bindings, fn (mixed $value): bool => $value === null || is_scalar($value)) : [];
    }

    /**
     * The stored error for a failure: never an exception message that could
     * quote SQL, bound values or connection details.
     */
    private function errorFor(Throwable $e): string
    {
        $run = Run::query()->find($this->runId);

        return match (true) {
            $e instanceof QueryFailed => QueryRunner::auditError($e, $run instanceof Run ? $this->redactedValues($run) : []),
            $e instanceof MaxAttemptsExceededException => 'The run timed out or its worker stopped.',
            default => 'The run failed unexpectedly. The application log has the details.',
        };
    }
}
