<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use TacticalNinj4\DbExplorer\Drivers\Postgres\QueryRunner;
use TacticalNinj4\DbExplorer\Events\ScheduleFailed;
use TacticalNinj4\DbExplorer\Exceptions\QueryFailed;
use TacticalNinj4\DbExplorer\Jobs\Concerns\TracksRun;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\Schedule;
use TacticalNinj4\DbExplorer\Notifications\ScheduledQueryFinished;

/**
 * A scheduled query's run (PRD §7.9). The tick dispatched this with the
 * schedule's id in `meta`; the schedule's saved query runs as its creator,
 * whose admin role TracksRun re-resolves (a demoted creator fails the run
 * before anything runs, and the next tick disables the schedule).
 *
 * When the query finishes, the schedule's `notify` settings decide who
 * hears about it: mail to the configured addresses on success, failure, or
 * a row threshold. A failure also fires {@see ScheduleFailed}.
 */
final class RunScheduledQuery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TracksRun;

    public const TYPE = 'scheduled_query';

    protected function process(Run $run, string $connection): void
    {
        $schedule = Schedule::query()->find((int) ($run->meta['schedule_id'] ?? 0));

        if ($schedule === null || $schedule->savedQuery === null) {
            throw new RuntimeException('The schedule is gone.');
        }

        $saved = $schedule->savedQuery;

        try {
            $result = app(QueryRunner::class)->run($connection, (string) $saved->sql, [], (string) $run->role, [
                'label' => $run->label,
                'user_id' => $run->user_id,
            ]);

            $rows = $result->rowCount ?? $result->affectedRows ?? 0;

            $this->notify($schedule, $saved->name, 'succeeded', $rows, $result->durationMs, null);
        } catch (QueryFailed $exception) {
            $error = QueryRunner::auditError($exception, []);

            $this->notify($schedule, $saved->name, 'failed', null, null, $error);
            event(new ScheduleFailed($schedule->id, $run->id, $error));

            throw $exception;
        }
    }

    /**
     * Stamp the schedule and send the notification its settings ask for.
     *
     * @param  'succeeded'|'failed'  $status
     */
    private function notify(Schedule $schedule, string $name, string $status, ?int $rows, ?float $durationMs, ?string $error): void
    {
        Schedule::query()->whereKey($schedule->id)->update([
            'last_run_at' => now(),
            'last_run_id' => $this->runId,
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
            new ScheduledQueryFinished($name, $status, $rows, $durationMs, $error),
        );
    }
}
