<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Tests;

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
}
