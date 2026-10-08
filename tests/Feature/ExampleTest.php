<?php

declare(strict_types=1);

use njrdevs\DbExplorer\DbExplorer;

it('resolves the singleton', function () {
    expect(app(DbExplorer::class))->toBeInstanceOf(DbExplorer::class);
});

it('returns the same instance from the container', function () {
    expect(app(DbExplorer::class))->toBe(app(DbExplorer::class));
});

it('merges the package config', function () {
    expect(config('dbexplorer.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('dbexplorer::messages.placeholder'))->toBe('DbExplorer placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('dbexplorer::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('dbexplorer:placeholder')
        ->expectsOutputToContain('DbExplorer placeholder command executed.')
        ->assertSuccessful();
});
