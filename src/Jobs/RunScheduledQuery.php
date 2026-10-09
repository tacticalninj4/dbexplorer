<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Events\ScheduleFailed;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Jobs\Concerns\TracksRun;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Models\Schedule;
use TacticalNinj4\DbExplorer\Notifications\ScheduledQueryFinished;
use TacticalNinj4\DbExplorer\Sql\Classifier;
use TacticalNinj4\DbExplorer\Sql\NamedParameters;

/**
 * A scheduled query's run (PRD §7.9). The tick dispatched this with the
 * schedule's id in `meta`, as the admin who approved the schedule.
 *
 * Only the schedule's approved snapshot runs, never the saved query's
 * current SQL (docs/security.md §6). TracksRun re-resolves the approver's
 * role first; then the snapshot must be intact and approved by the run's
 * user, DDL needs `allow_ddl`, and without `allow_writes` the statement runs
 * in a read-only transaction, so a write fails at the database. A run that
 * fails because the approver lost the admin role, or because the approval
 * is gone, disables the schedule (audited).
 *
 * When the query finishes, the schedule's `notify` settings decide who
 * hears about it: mail to the configured addresses on success, failure, or
 * a row threshold. A failure also fires {@see ScheduleFailed}, after the
 * run's own audit row. The schedule's lock is released when the run ends,
 * so the next due tick runs it again, failed or not.
 */
final class RunScheduledQuery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksRun {
        TracksRun::handle as handleRun;
    }

    public const TYPE = 'scheduled_query';

    public const UNAPPROVED = 'The schedule has no approved SQL. An admin needs to re-sync it.';

    /** Whether TracksRun let process() start, i.e. the approver's role held. */
    private bool $processed = false;

    public function handle(DbExplorer $explorer): void
    {
        $run = Run::query()->find($this->runId);

        if (! $run instanceof Run || $run->status !== RunStatus::QUEUED) {
            return;
        }

        $scheduleId = (int) ($run->meta['schedule_id'] ?? 0);

        try {
            $this->handleRun($explorer);
        } finally {
            Cache::lock(self::lockName($scheduleId))->forceRelease();

            $run->refresh();
            $schedule = Schedule::query()->find($scheduleId);

            if ($schedule instanceof Schedule && $run->status === RunStatus::FAILED) {
                if (! $this->processed) {
                    // Failed before it started: the approver is gone, no longer an admin, or the connection is.
                    $schedule->disable((string) $run->error, $run->id);
                }

                self::reportFailure($schedule, $run);
            }
        }
    }

    /**
     * The cache lock that keeps a schedule from running twice at once.
     */
    public static function lockName(int $scheduleId): string
    {
        return "dbx:schedule:{$scheduleId}";
    }

    /**
     * Mail the schedule's failure recipients and fire ScheduleFailed. Call it
     * once the run has finished, so the run's audit row is written first.
     */
    public static function reportFailure(Schedule $schedule, Run $run): void
    {
        $error = (string) $run->error;

        self::notify($schedule, $run->id, 'failed', null, null, $error);

        event(new ScheduleFailed($schedule->id, $run->id, $error));
    }

    protected function process(Run $run, string $connection): void
    {
        $this->processed = true;

        $schedule = Schedule::query()->find((int) ($run->meta['schedule_id'] ?? 0));

        if ($schedule === null) {
            throw new RuntimeException('The schedule is gone.');
        }

        if (! $schedule->isApproved() || $schedule->approved_by !== $run->user_id) {
            $schedule->disable(self::UNAPPROVED, $run->id);

            throw new QueryFailed(self::UNAPPROVED);
        }

        $sql = (string) $schedule->sql;

        if (Classifier::isDdl($sql) && ! $schedule->allow_ddl) {
            throw new QueryFailed('The schedule runs DDL, but its approval does not allow DDL.');
        }

        [$sql, $bindings] = NamedParameters::bind($sql, (array) $schedule->params, []);

        $result = app(QueryRunner::class)->run($connection, $sql, $bindings, (string) $run->role, [
            'label' => $run->label,
            'user_id' => $run->user_id,
            'read_only' => ! $schedule->allow_writes,
        ]);

        self::notify($schedule, $run->id, 'succeeded', $result->rowCount ?? $result->affectedRows ?? 0, $result->durationMs, null);
    }

    /**
     * Stamp the schedule and send the notification its settings ask for.
     *
     * @param  'succeeded'|'failed'  $status
     */
    private static function notify(Schedule $schedule, int $runId, string $status, ?int $rows, ?float $durationMs, ?string $error): void
    {
        Schedule::query()->whereKey($schedule->id)->update([
            'last_run_at' => now(),
            'last_run_id' => $runId,
        ]);

        $settings = $schedule->notifySettings();

        if ($settings['mail'] === []) {
            return;
        }

        // The settings speak of success and failure; the run of succeeded.
        $outcome = $status === 'succeeded' ? 'success' : 'failure';

        $wants = in_array($outcome, $settings['on'], true)
            || ($status === 'succeeded' && in_array('threshold', $settings['on'], true)
                && $settings['threshold_rows'] !== null && $rows !== null && $rows >= $settings['threshold_rows']);

        if (! $wants) {
            return;
        }

        Notification::route('mail', $settings['mail'])->notify(
            new ScheduledQueryFinished((string) $schedule->savedQuery?->name, $status, $rows, $durationMs, $error),
        );
    }
}
