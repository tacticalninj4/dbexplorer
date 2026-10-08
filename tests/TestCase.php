<?php

declare(strict_types=1);

namespace njrdevs\DbExplorer\Tests;

use njrdevs\DbExplorer\DbExplorerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DbExplorerServiceProvider::class,
        ];
    }
}
