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
     * The seeded users and their role on every label. /login/{role} signs in as one.
     *
     * @var array<string, string>
     */
    public const USERS = [
        'test@example.com' => Role::ADMIN,
        'editor@example.com' => Role::EDITOR,
        'viewer@example.com' => Role::VIEWER,
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        $config = $this->app['config'];

        $config->set('dbexplorer.enabled', true);

        // Mirror the pgsql tests, so S13 and S14 can be checked by hand:
        // fixture.secrets (and the view over it) is hidden, and
        // users.password / api_token are masked by the default rules.
        $config->set('dbexplorer.hidden', [...(array) $config->get('dbexplorer.hidden', []), 'fixture.secrets']);
        $config->set('dbexplorer.masking', ['*.password', '*.*token*', '*.*secret*']);

        // A pg_dump at least as new as the server, e.g. Homebrew's libpq when
        // Postgres.app's is older than the dbx-pg container.
        if (is_string($dump = env('DBX_WORKBENCH_PG_DUMP')) && $dump !== '') {
            $config->set('dbexplorer.backup.pg_dump_path', $dump);
        }

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
            return isset(self::USERS[$user->email]);
        });

        // Each role logs in as its own dbx_* login (README "Assign roles").
        DbExplorer::roleUsing(function ($user): ?string {
            return self::USERS[$user->email] ?? null;
        });
    }
}
