<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\DbExplorerServiceProvider;

it('merges the package defaults', function () {
    expect(config('dbexplorer.path'))->toBe('dbexplorer')
        ->and(config('dbexplorer.domain'))->toBeNull()
        ->and(config('dbexplorer.middleware'))->toBe(['web', 'auth'])
        ->and(config('dbexplorer.ip_allowlist'))->toBe([])
        ->and(config('dbexplorer.limits.viewer'))->toBe(['timeout' => 30, 'rows' => 1000])
        ->and(config('dbexplorer.limits.queries_per_minute'))->toBe(60)
        ->and(config('dbexplorer.hidden'))->toContain('pg_toast*')
        ->and(config('dbexplorer.masking'))->toContain('*.password');
});

it('keeps host overrides when the package config is merged', function () {
    config(['dbexplorer.path' => 'admin/db', 'dbexplorer.connections' => ['reporting' => ['viewer' => 'reporting_ro']]]);

    (new DbExplorerServiceProvider(app()))->register();

    expect(config('dbexplorer.path'))->toBe('admin/db')
        ->and(config('dbexplorer.connections'))->toBe(['reporting' => ['viewer' => 'reporting_ro']])
        ->and(config('dbexplorer.limits.viewer.timeout'))->toBe(30);
});

it('S1 is disabled unless DBEXPLORER_ENABLED is set', function () {
    $config = require __DIR__.'/../../config/dbexplorer.php';

    expect($config['enabled'])->toBeFalse();
});

it('leaves storage and audit settings to the audit log task', function () {
    $config = require __DIR__.'/../../config/dbexplorer.php';

    expect(array_keys($config))->toBe([
        'enabled', 'path', 'domain', 'middleware', 'ip_allowlist', 'connections',
        'limits', 'hidden', 'masking', 'queue', 'backup',
    ]);
});
