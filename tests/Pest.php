<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TacticalNinj4\DbExplorer\Models\Run;
use TacticalNinj4\DbExplorer\Tests\Jobs\RunStatements;
use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;
use TacticalNinj4\DbExplorer\Tests\TestCase;
use Workbench\App\Models\User;

// The pg_dump binary for BackupTest: one whose major version can dump the
// test server — the docker container's own pg_dump through a wrapper, when
// the local binaries are older than the server — else the newest on PATH.
// BackupTest skips the round-trip when nothing matches.
if (! defined('DB_DUMP_PATH')) {
    $best = '';
    $bestVersion = 0;

    foreach (array_unique(explode(':', (string) getenv('PATH'))) as $dir) {
        $candidate = $dir.'/pg_dump';

        if (! is_file($candidate) || ! is_executable($candidate)) {
            continue;
        }

        $output = (string) @shell_exec(escapeshellarg($candidate).' --version 2>/dev/null');
        $version = (int) (preg_match('/(\d+)\.\d+/', $output, $m) ? $m[1] : 0);

        if ($version > $bestVersion) {
            $best = $candidate;
            $bestVersion = $version;
        }
    }

    $serverVersion = 0;

    if (getenv('DB_PGSQL_HOST') !== false) {
        $dsn = sprintf('host=%s port=%s user=%s password=%s dbname=postgres', getenv('DB_PGSQL_HOST'), getenv('DB_PGSQL_PORT') ?: '5432', getenv('DB_PGSQL_USERNAME') ?: 'postgres', getenv('DB_PGSQL_PASSWORD') ?: '');
        $number = @shell_exec('psql '.escapeshellarg($dsn).' -tAc "SHOW server_version_num" 2>/dev/null');

        if (is_string($number) && ctype_digit(trim($number))) {
            $serverVersion = intdiv((int) trim($number), 10000);
        }
    }

    if ($serverVersion > 0 && $bestVersion < $serverVersion) {
        $wrapper = __DIR__.'/Pgsql/scripts/docker-pgdump.sh';
        $container = (string) @shell_exec('docker exec dbx-pg pg_dump --version 2>/dev/null');
        $containerVersion = (int) (preg_match('/(\d+)\.\d+/', $container, $m) ? $m[1] : 0);

        if ($containerVersion >= $serverVersion && is_executable($wrapper)) {
            $best = $wrapper;
        }
    }

    define('DB_DUMP_PATH', $best);
}

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Unit');

// Postgres integration tests: skipped unless DB_PGSQL_HOST is set (docs/TESTING.md §3).
uses(PgsqlTestCase::class)->group('pgsql')->in(__DIR__.'/Pgsql');

/**
 * An unsaved workbench user, enough for actingAs() and gates without a database.
 */
function makeUser(int $id = 1, string $email = 'dev@example.com'): User
{
    $user = new User;
    $user->forceFill(['id' => $id, 'name' => 'Dev', 'email' => $email]);

    return $user;
}

/**
 * The SQLSTATE that $callback's query fails with, or null when it succeeds.
 */
function sqlState(callable $callback): ?string
{
    try {
        $callback();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/**
 * Create the users table on the default connection if needed, and a saved
 * workbench user for each id, resolvable through the `users` auth provider.
 */
function createUsers(int ...$ids): void
{
    config(['auth.providers.users.model' => User::class]);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    foreach ($ids as $id) {
        DB::table('users')->insert(['id' => $id, 'name' => "User {$id}", 'email' => "user{$id}@example.com"]);
    }
}

/**
 * A run owned by user 1 as viewer on `main`, queued unless overridden.
 *
 * @param  array<string, mixed>  $attributes
 */
function createRun(array $attributes = []): Run
{
    return Run::query()->create($attributes + [
        'type' => RunStatements::TYPE,
        'user_id' => '1',
        'label' => 'main',
        'role' => 'viewer',
        'meta' => [Run::USER_PROVIDER => 'users'],
    ]);
}
