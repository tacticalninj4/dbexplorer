<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Role;

it('orders the roles viewer < editor < admin', function (string $have, string $need, bool $expected) {
    expect(Role::atLeast($have, $need))->toBe($expected);
})->with([
    ['viewer', 'viewer', true],
    ['editor', 'viewer', true],
    ['editor', 'editor', true],
    ['admin', 'viewer', true],
    ['admin', 'editor', true],
    ['admin', 'admin', true],
    ['viewer', 'editor', false],
    ['viewer', 'admin', false],
    ['editor', 'admin', false],
]);

it('never grants anything to an unknown role', function (string $have, string $need) {
    expect(Role::atLeast($have, $need))->toBeFalse();
})->with([
    ['superuser', 'viewer'],
    ['Admin', 'viewer'],
    ['', 'viewer'],
    ['admin', 'superuser'],
    ['admin', ''],
]);

it('knows which strings are roles', function () {
    expect(Role::ALL)->toBe([Role::VIEWER, Role::EDITOR, Role::ADMIN])
        ->and(Role::isValid('editor'))->toBeTrue()
        ->and(Role::isValid('EDITOR'))->toBeFalse()
        ->and(Role::isValid('owner'))->toBeFalse();
});
