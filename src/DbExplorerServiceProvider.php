<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TacticalNinj4\DbExplorer\Http\Middleware\Authorize;
use TacticalNinj4\DbExplorer\Http\Middleware\IpAllowlist;

class DbExplorerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dbexplorer.php', 'dbexplorer');

        $this->app->singleton(DbExplorer::class);
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

        if (! $this->app->runningInConsole()) {
            return;
        }

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
     * Register the routes and the `dbexplorer` rate limiter.
     */
    protected function registerRoutes(): void
    {
        RateLimiter::for('dbexplorer', function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('dbexplorer.limits.queries_per_minute', 60))->by($key);
        });

        Route::group([
            'domain' => config('dbexplorer.domain'),
            'prefix' => config('dbexplorer.path', 'dbexplorer'),
            'middleware' => array_merge(
                [IpAllowlist::class],
                (array) config('dbexplorer.middleware', []),
                [Authorize::class],
            ),
            'as' => 'dbexplorer.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/dbexplorer.php');
        });
    }
}
