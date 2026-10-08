<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Tests;

use Illuminate\Foundation\Application;
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

    /**
     * Feature tests keep the metadata store in SQLite :memory: (docs/TESTING.md §2),
     * set explicitly because Testbench 7 defaults to MySQL.
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
        // Hermetic against a stray skeleton .env (e.g. CACHE_STORE=database after composer build).
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.store', 'array');
        $app['config']->set('session.driver', 'array');
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
