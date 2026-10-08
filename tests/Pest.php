<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Tests\PgsqlTestCase;
use TacticalNinj4\DbExplorer\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Unit');

// Postgres integration tests: skipped unless DB_PGSQL_HOST is set (docs/TESTING.md §3).
uses(PgsqlTestCase::class)->group('pgsql')->in(__DIR__.'/Pgsql');
