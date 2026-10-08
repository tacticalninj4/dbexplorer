<?php

declare(strict_types=1);

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
