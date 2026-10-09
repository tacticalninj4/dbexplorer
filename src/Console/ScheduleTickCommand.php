<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Console;

use Cron\CronExpression;
use Illuminate\Auth\AuthManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use TacticalNinj4\DbExplorer\DbExplorer as Explorer;
use TacticalNinj4\DbExplorer\Jobs\RunScheduledQuery;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Models\RunStatus;
use TacticalNinj4\DbExplorer\Models\Schedule;
use TacticalNinj4\DbExplorer\Role;
use Throwable;

/**
 * The scheduler entry point for scheduled queries (PRD §7.9): registered
 * `->everyMinute()`, so the host only needs the standard `schedule:run`
 * cron. For each enabled, due schedule it dispatches one run under a cache
 * lock that lives as long as the background timeout, so a slow run is
 * never dispatched twice.
 *
 * A schedule runs as the admin who approved its SQL snapshot. One without
 * an approved snapshot (e.g. from before snapshots existed) is disabled and
 * audited. One whose approver lost the admin role gets a failed run, is
 * disabled and audited, and fires ScheduleFailed — the job's own role
 * re-check still guards whatever a minute of drift could dispatch.
 */
class ScheduleTickCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dbx:schedule:tick';

    /**
     * @var string
     */
    protected $description = 'Dispatch the scheduled DbExplorer queries that are due';

    public const DEMOTED = 'Permission changed: the schedule\'s approver no longer has the admin role.';

    public function handle(): int
    {
        $dispatched = 0;

        foreach (Schedule::query()->where('enabled', true)->with('savedQuery')->get() as $schedule) {
            if ($schedule->savedQuery === null || ! $this->isDue($schedule)) {
                continue;
            }

            if (! $schedule->isApproved()) {
                $schedule->disable(RunScheduledQuery::UNAPPROVED);
                $this->line("Disabled schedule #{$schedule->id}: it has no approved SQL.");

                continue;
            }

            // The lock expires with the background timeout, so a crashed
            // worker can't wedge the schedule forever; the job releases it.
            $lock = Cache::lock(RunScheduledQuery::lockName($schedule->id), max(60, (int) config('dbexplorer.limits.background.timeout', 3600)));

            if (! $lock->get()) {
                continue;
            }

            $provider = config('auth.guards.'.app(AuthManager::class)->getDefaultDriver().'.provider');

            // The schedule runs as the admin who approved its SQL.
            $run = Run::query()->create([
                'type' => RunScheduledQuery::TYPE,
                'user_id' => (string) $schedule->approved_by,
                'label' => $schedule->savedQuery->label,
                'role' => Role::ADMIN,
                'meta' => [Run::USER_PROVIDER => is_string($provider) ? $provider : null, 'schedule_id' => $schedule->id],
            ]);

            $schedule->update(['last_run_at' => now(), 'last_run_id' => $run->id]);

            if (! $this->approverIsAdmin($schedule, $provider)) {
                $run->finish(RunStatus::FAILED, self::DEMOTED);
                $lock->release();
                $schedule->disable(self::DEMOTED, $run->id);
                RunScheduledQuery::reportFailure($schedule, $run);
                $this->line("Disabled schedule #{$schedule->id}: its approver is no longer an admin.");

                continue;
            }

            RunScheduledQuery::dispatch($run->id);
            $dispatched++;
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} scheduled ".($dispatched === 1 ? 'query' : 'queries').'.');
        }

        return self::SUCCESS;
    }

    private function isDue(Schedule $schedule): bool
    {
        try {
            $expression = new CronExpression($schedule->cron);
        } catch (Throwable) {
            return false;
        }

        $timezone = is_string($schedule->timezone) && $schedule->timezone !== '' ? $schedule->timezone : config('app.timezone');

        return $expression->isDue(now($timezone)->format('Y-m-d H:i:s'));
    }

    /**
     * The approver, resolved through the default guard's provider, still has
     * the admin role on the schedule's label.
     */
    private function approverIsAdmin(Schedule $schedule, mixed $provider): bool
    {
        $user = is_string($provider)
            ? app(AuthManager::class)->createUserProvider($provider)?->retrieveById((string) $schedule->approved_by)
            : null;

        return $user instanceof Authenticatable
            && app(Explorer::class)->roleFor($user, (string) $schedule->savedQuery?->label) === Role::ADMIN;
    }
}
