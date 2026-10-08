<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use TacticalNinj4\DbExplorer\DbExplorerServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DbExplorerServiceProvider::class,
        ];
    }

    /**
     * Feature tests keep the metadata store in SQLite :memory: (docs/TESTING.md §2).
     * Set explicitly because Testbench 7 defaults to MySQL.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
