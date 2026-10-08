<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Exceptions\ConnectionNotAllowedException;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;

beforeEach(function () {
    config([
        'dbexplorer.connections' => [
            'main' => [
                'viewer' => 'pgsql_readonly',
                'editor' => 'pgsql_editor',
                'admin' => 'pgsql',
            ],
            'staging' => [
                'viewer' => 'staging_readonly',
            ],
        ],
    ]);
});

it('S7 resolves an allowlisted label and role to its connection', function (string $label, string $role, string $connection) {
    expect(DbExplorer::hasConnection($label))->toBeTrue()
        ->and(DbExplorer::connectionFor($label, $role))->toBe($connection);
})->with([
    ['main', 'viewer', 'pgsql_readonly'],
    ['main', 'editor', 'pgsql_editor'],
    ['main', 'admin', 'pgsql'],
    ['staging', 'viewer', 'staging_readonly'],
]);

it('S7 rejects a label that is not allowlisted', function (string $label) {
    expect(DbExplorer::hasConnection($label))->toBeFalse();

    DbExplorer::connectionFor($label, 'viewer');
})->with([
    'unknown label' => 'reporting',
    'raw connection name' => 'pgsql_readonly',
    'dot-notation path' => 'main.viewer',
    'empty label' => '',
])->throws(ConnectionNotAllowedException::class, 'is not listed in dbexplorer.connections');

it('S7 rejects a role with no connection mapped for the label', function () {
    DbExplorer::connectionFor('staging', 'admin');
})->throws(ConnectionNotAllowedException::class, 'has no connection mapped for the [admin] role');

it('S7 rejects a role name that is not a role', function () {
    DbExplorer::connectionFor('main', 'superuser');
})->throws(ConnectionNotAllowedException::class);

it('S7 rejects every label when no connections are configured', function () {
    config(['dbexplorer.connections' => null]);

    DbExplorer::connectionFor('main', 'viewer');
})->throws(ConnectionNotAllowedException::class);
