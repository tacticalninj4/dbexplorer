<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
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

it('schedules dbx:prune daily and dbx:schedule:tick every minute, once each', function () {
    $expressions = [];

    foreach (app(Schedule::class)->events() as $event) {
        foreach (['dbx:prune', 'dbx:schedule:tick', 'dbx:audit:verify'] as $command) {
            if (str_contains((string) $event->command, $command)) {
                $expressions[$command][] = $event->expression;
            }
        }
    }

    // dbx:audit:verify is the host's to schedule, with its own alerting.
    expect($expressions)->toBe([
        'dbx:prune' => ['0 0 * * *'],
        'dbx:schedule:tick' => ['* * * * *'],
    ]);
});
