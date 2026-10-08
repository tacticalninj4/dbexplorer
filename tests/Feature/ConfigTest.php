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
        ->and(config('dbexplorer.hidden'))->toContain('pg_toast*', '*.dbx_*', 'dbexplorer.*')
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

it('ships every top-level setting, with storage and audit defaults', function () {
    $config = require __DIR__.'/../../config/dbexplorer.php';

    expect(array_keys($config))->toBe([
        'enabled', 'path', 'domain', 'middleware', 'ip_allowlist', 'connections',
        'limits', 'confirm_password', 'hidden', 'masking', 'queue', 'backup', 'storage', 'audit',
    ])
        ->and($config['storage'])->toBe(['connection' => null, 'disk' => 'local', 'file_ttl_hours' => 24])
        ->and($config['audit']['retention_days'])->toBe(90)
        ->and($config['audit']['log_channel'])->toBeNull()
        ->and($config['confirm_password'])->toBeTrue();
});

it('reads the storage connection from DBEXPLORER_STORAGE_CONNECTION', function () {
    $_ENV['DBEXPLORER_STORAGE_CONNECTION'] = $_SERVER['DBEXPLORER_STORAGE_CONNECTION'] = 'dbexplorer_meta';

    try {
        $config = require __DIR__.'/../../config/dbexplorer.php';
    } finally {
        unset($_ENV['DBEXPLORER_STORAGE_CONNECTION'], $_SERVER['DBEXPLORER_STORAGE_CONNECTION']);
    }

    expect($config['storage']['connection'])->toBe('dbexplorer_meta');
});
