<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer;

use Illuminate\Support\ServiceProvider;

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
        $this->loadRoutesFrom(__DIR__.'/../routes/dbexplorer.php');

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
}
