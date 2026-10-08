<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TacticalNinj4\DbExplorer\DbExplorerServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Config applied before the package boots, on top of the test defaults.
     *
     * @var array<string, mixed>
     */
    protected array $bootConfig = [];

    protected function getPackageProviders($app): array
    {
        return [
            DbExplorerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('dbexplorer.enabled', true);
        $app['config']->set($this->bootConfig);
    }

    /**
     * Rebuild the application with config that must be set before the package
     * boots, such as `enabled`, `path`, `domain` or `middleware`.
     *
     * @param  array<string, mixed>  $config
     */
    public function bootWith(array $config): static
    {
        $this->bootConfig = $config;

        $this->refreshApplication();

        return $this;
    }
}
