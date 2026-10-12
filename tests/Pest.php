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

/**
 * For the "about 1 MB" performance tests: how many times longer $run takes
 * on $input($count) than on $input($count / 10), the large input's time in
 * seconds (each the best of three tries), and $run's result on it.
 *
 * A fixed wall-clock bound flaked on a loaded machine (2.7 s for work that
 * takes 0.3 s idle). The ratio is what proves linear time (linear work gives
 * about 10, quadratic about 100), and it's measured in the process's own CPU
 * time, which waiting for a busy CPU doesn't inflate. Windows' CPU clock is
 * too coarse (15.6 ms) for the small input, so it uses wall time.
 *
 * @template T
 *
 * @param  callable(string): T  $run
 * @param  callable(int): string  $input
 * @return array{0: float, 1: float, 2: T}
 */
function linearScaling(callable $run, callable $input, int $count): array
{
    $clock = PHP_OS_FAMILY === 'Windows'
        ? fn (): float => hrtime(true) / 1e9
        : function (): float {
            $usage = getrusage();

            return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
        };

    $best = function (string $sql) use ($run, $clock): array {
        $seconds = INF;
        $result = null;

        for ($try = 0; $try < 3; $try++) {
            $started = $clock();
            $result = $run($sql);
            $seconds = min($seconds, $clock() - $started);
        }

        return [$seconds, $result];
    };

    [$small] = $best($input(intdiv($count, 10)));
    [$large, $result] = $best($input($count));

    return [$large / max($small, 1e-6), $large, $result];
}
