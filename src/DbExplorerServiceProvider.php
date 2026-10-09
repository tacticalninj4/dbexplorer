<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TacticalNinj4\DbExplorer\Audit\AuditLog;
use TacticalNinj4\DbExplorer\Console\AuditVerifyCommand;
use TacticalNinj4\DbExplorer\Console\InstallCommand;
use TacticalNinj4\DbExplorer\Console\PruneCommand;
use TacticalNinj4\DbExplorer\Console\ScheduleTickCommand;
use TacticalNinj4\DbExplorer\Http\Middleware\Authorize;
use TacticalNinj4\DbExplorer\Http\Middleware\ContentSecurityPolicy;
use TacticalNinj4\DbExplorer\Http\Middleware\IpAllowlist;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;

class DbExplorerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dbexplorer.php', 'dbexplorer');

        $this->app->singleton(DbExplorer::class);

        $this->app->singleton(AuditLog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Disabled means no routes at all, so every DbExplorer URL is a 404 (S1).
        if ((bool) config('dbexplorer.enabled')) {
            $this->registerRoutes();
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dbexplorer');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'dbexplorer');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Exports and other run results carry real data: a disk that serves
        // by URL (S16) defeats the signed-download check around them.
        $this->warnAboutPublicDisk();

        // The host's `schedule:run` prunes runs, history, files and audit
        // entries daily, and dispatches the due scheduled queries every
        // minute; nothing here needs its own cron entry (PRD §7.9).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('dbx:prune')->daily();
            $schedule->command('dbx:schedule:tick')->everyMinute();
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            AuditVerifyCommand::class,
            InstallCommand::class,
            PruneCommand::class,
            ScheduleTickCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/dbexplorer.php' => config_path('dbexplorer.php'),
        ], ['dbexplorer', 'dbexplorer-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/dbexplorer'),
        ], ['dbexplorer', 'dbexplorer-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/dbexplorer'),
        ], ['dbexplorer', 'dbexplorer-lang']);

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['dbexplorer', 'dbexplorer-migrations']);
    }

    /**
     * Register the routes and the `dbexplorer` and `dbexplorer-confirm` rate limiters.
     */
    protected function registerRoutes(): void
    {
        RateLimiter::for('dbexplorer', function (Request $request): Limit {
            return Limit::perMinute((int) config('dbexplorer.limits.queries_per_minute', 60))->by($this->limiterKey($request));
        });

        // Password confirmation attempts (S5): 5 per minute, whatever the outcome.
        RateLimiter::for('dbexplorer-confirm', function (Request $request): Limit {
            return Limit::perMinute(5)->by($this->limiterKey($request));
        });

        Route::group([
            'domain' => config('dbexplorer.domain'),
            'prefix' => config('dbexplorer.path', 'dbexplorer'),
            'middleware' => array_merge(
                [ContentSecurityPolicy::class, IpAllowlist::class],
                (array) config('dbexplorer.middleware', []),
                [Authorize::class, ResolveRole::class],
            ),
            'as' => 'dbexplorer.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/dbexplorer.php');
        });
    }

    /**
     * Rate limit per user, so one IP can't share or reset a budget; per IP for guests.
     */
    protected function limiterKey(Request $request): string
    {
        $user = $request->user();

        return $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();
    }

    /**
     * Warn, once per boot, when the storage disk for run results is public:
     * its files would be downloadable by guessing or leaking a URL, whatever
     * the signed route around them checks (S16).
     */
    protected function warnAboutPublicDisk(): void
    {
        $disk = config('dbexplorer.storage.disk');

        if (! is_string($disk) || $disk === '') {
            $disk = 'local';
        }

        $config = config("filesystems.disks.{$disk}");

        if (is_array($config) && (($config['driver'] ?? null) === 'public' || ($config['visibility'] ?? null) === 'public')) {
            Log::warning("The DbExplorer storage disk [{$disk}] is public: run results (exports) would be reachable by URL. Point dbexplorer.storage.disk at a private disk.");
        }
    }
}
