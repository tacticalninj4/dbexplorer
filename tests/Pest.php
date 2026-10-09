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
