<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use TacticalNinj4\DbExplorer\DbExplorer;
use TacticalNinj4\DbExplorer\DbExplorerServiceProvider;
use TacticalNinj4\DbExplorer\Facades\DbExplorer as DbExplorerFacade;

it('boots the service provider', function () {
    expect(app()->getProvider(DbExplorerServiceProvider::class))
        ->toBeInstanceOf(DbExplorerServiceProvider::class);
});

it('registers DbExplorer as a singleton', function () {
    expect(app(DbExplorer::class))
        ->toBeInstanceOf(DbExplorer::class)
        ->toBe(app(DbExplorer::class));
});

it('resolves the facade to the singleton', function () {
    expect(DbExplorerFacade::getFacadeRoot())->toBe(app(DbExplorer::class));
});

it('merges the package config', function () {
    expect(config('dbexplorer'))->toBeArray();
});

it('registers the publish tags', function (string $tag) {
    expect(ServiceProvider::pathsToPublish(DbExplorerServiceProvider::class, $tag))->not->toBeEmpty();
})->with([
    'dbexplorer',
    'dbexplorer-config',
    'dbexplorer-views',
    'dbexplorer-lang',
    'dbexplorer-migrations',
]);
