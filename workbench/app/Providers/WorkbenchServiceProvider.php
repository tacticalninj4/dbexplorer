<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Role;
use TacticalNinj4\DbExplorer\Tests\PgsqlFixture;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * The workbench database when DB_PGSQL_HOST is set (docs/TESTING.md §3.1).
     */
    public const DATABASE = 'dbx_workbench';

    /**
     * Register services.
     */
    public function register(): void
    {
        $config = $this->app['config'];

        $config->set('dbexplorer.enabled', true);

        // Without Postgres the workbench keeps the skeleton's SQLite database.
        if (! PgsqlFixture::configured()) {
            return;
        }

        // The superuser owns the app tables; the explorer logs in as the dbx_* roles.
        $config->set('database.connections.pgsql', PgsqlFixture::connection(self::DATABASE));
        $config->set('database.default', 'pgsql');

        $main = [];

        foreach (PgsqlFixture::roleConnections(self::DATABASE) as $role => $connection) {
            $config->set("database.connections.pgsql_{$role}", $connection);
            $main[$role] = "pgsql_{$role}";
        }

        $config->set('dbexplorer.connections', ['main' => $main]);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::define('viewDbExplorer', function ($user): bool {
            return $user->email === 'test@example.com';
        });

        DbExplorer::roleUsing(function (): string {
            return Role::ADMIN;
        });
    }
}
