<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Console;

use Cron\CronExpression;
use Illuminate\Auth\AuthManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use TacticalNinj4\DbExplorer\DbExplorer as Explorer;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Jobs\RunScheduledQuery;
use TacticalNinj4\DbExplorer\Models\Run;
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
 * A creator who has lost the admin role gets their schedule disabled here,
 * audited — the job's own role re-check still guards whatever a minute of
 * drift could dispatch.
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

    public function handle(): int
    {
        $dispatched = 0;

        foreach (Schedule::query()->where('enabled', true)->with('savedQuery')->get() as $schedule) {
            if ($schedule->savedQuery === null || ! $this->isDue($schedule)) {
                continue;
            }

            if (! $this->creatorIsAdmin($schedule)) {
                $schedule->update(['enabled' => false]);
                DbExplorer::audit([
                    'action' => 'schedule.disabled',
                    'user_id' => $schedule->user_id,
                    'label' => $schedule->savedQuery->label,
                    'outcome' => 'error',
                    'error' => 'The creator no longer has the admin role.',
                    'meta' => ['schedule_id' => $schedule->id, 'saved_query_id' => $schedule->saved_query_id],
                ]);
                $this->line("Disabled schedule #{$schedule->id}: its creator is no longer an admin.");

                continue;
            }

            // The lock expires with the background timeout, so a crashed
            // worker can't wedge the schedule forever.
            $lock = Cache::lock("dbx:schedule:{$schedule->id}", max(60, (int) config('dbexplorer.limits.background.timeout', 3600)));

            if (! $lock->get()) {
                continue;
            }

            $provider = config('auth.guards.'.app(AuthManager::class)->getDefaultDriver().'.provider');

            $run = Run::query()->create([
                'type' => RunScheduledQuery::TYPE,
                'user_id' => $schedule->user_id,
                'label' => $schedule->savedQuery->label,
                'role' => Role::ADMIN,
                'meta' => [Run::USER_PROVIDER => is_string($provider) ? $provider : null, 'schedule_id' => $schedule->id],
            ]);

            RunScheduledQuery::dispatch($run->id);

            $schedule->update(['last_run_at' => now(), 'last_run_id' => $run->id]);
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
     * The creator, resolved through the default guard's provider, still has
     * the admin role on the schedule's label.
     */
    private function creatorIsAdmin(Schedule $schedule): bool
    {
        $provider = config('auth.guards.'.app(AuthManager::class)->getDefaultDriver().'.provider');
        $user = is_string($provider)
            ? app(AuthManager::class)->createUserProvider($provider)?->retrieveById($schedule->user_id)
            : null;

        return $user instanceof Authenticatable
            && app(Explorer::class)->roleFor($user, (string) $schedule->savedQuery->label) === Role::ADMIN;
    }
}
