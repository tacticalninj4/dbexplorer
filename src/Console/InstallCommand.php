<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Console;

use Illuminate\Console\Command;
use RuntimeException;

class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'dbx:install {--force : Overwrite an existing config/dbexplorer.php}';

    /**
     * @var string
     */
    protected $description = 'Publish the DbExplorer config and print the remaining setup steps';

    public function handle(): int
    {
        $this->publishConfig();

        $this->newLine();
        $this->line('<comment>Next steps</comment>');

        $this->step('1. Enable DbExplorer and give the audit log its own HMAC key (.env)', $this->envLines());

        $this->step('2. Decide who gets in: define the gate in App\Providers\AppServiceProvider::boot()', <<<'PHP'
            use Illuminate\Support\Facades\Gate;

            Gate::define('viewDbExplorer', function ($user) {
                return in_array($user->email, [
                    // 'you@example.com',
                ], true);
            });
            PHP);

        $this->step('3. Map users to a role per connection label (same place; without it everyone is a viewer)', <<<'PHP'
            use TacticalNinj4\DbExplorer\Facades\DbExplorer;

            DbExplorer::roleUsing(function ($user, string $label) {
                return 'viewer'; // 'viewer', 'editor', 'admin', or null for no access to $label
            });
            PHP);

        $this->step(
            '4. Give each role its own PostgreSQL login. The database grants are the real boundary (docs/security.md).',
            "Run this SQL and add matching connections to config/database.php. Then point\n".
            "dbexplorer.connections at them (viewer => pgsql_readonly, editor => pgsql_editor, ...).\n".
            "Recommended: set DBEXPLORER_STORAGE_CONNECTION to a connection with 'search_path' => 'dbexplorer'.\n\n".
            $this->roleRecipe(),
        );

        $this->step('5. Create the dbx_* tables', 'php artisan migrate');

        $this->step('6. Keep the scheduler and a queue worker running', <<<'TEXT'
            Schedule both daily, in routes/console.php (Laravel 11+) or app/Console/Kernel.php:
                Schedule::command('dbx:prune')->daily();         // $schedule->command(...) in Kernel.php
                Schedule::command('dbx:audit:verify')->daily();  // alert when it fails
            Background features (exports, imports, scripts, backups) will need: php artisan queue:work
            TEXT);

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        // The same file the `dbexplorer-config` publish tag copies.
        $target = config_path('dbexplorer.php');
        $exists = file_exists($target);

        if ($exists && ! $this->option('force')) {
            $this->warn('config/dbexplorer.php already exists, left unchanged. Use --force to overwrite it.');

            return;
        }

        if (! copy(__DIR__.'/../../config/dbexplorer.php', $target)) {
            throw new RuntimeException("Could not write {$target}.");
        }

        $this->info($exists ? 'Overwrote config/dbexplorer.php.' : 'Published config/dbexplorer.php.');
    }

    private function envLines(): string
    {
        $key = config('dbexplorer.audit.key');

        if (is_string($key) && $key !== '') {
            return "DBEXPLORER_ENABLED=true\n# DBEXPLORER_AUDIT_KEY is already set.";
        }

        return "DBEXPLORER_ENABLED=true\n".
            'DBEXPLORER_AUDIT_KEY=base64:'.base64_encode(random_bytes(32))."\n".
            "# Freshly generated and not saved anywhere. Without it APP_KEY is used, and\n".
            '# rotating APP_KEY would then break verification of existing audit rows.';
    }

    private function roleRecipe(): string
    {
        return (string) file_get_contents(__DIR__.'/../../resources/sql/postgres-roles.sql');
    }

    private function step(string $title, string $body): void
    {
        $this->newLine();
        $this->line("<info>{$title}</info>");
        $this->newLine();

        foreach (explode("\n", rtrim($body)) as $line) {
            $this->line($line === '' ? '' : '    '.$line);
        }
    }
}
