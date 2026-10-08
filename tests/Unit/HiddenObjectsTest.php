<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Security\HiddenObjects;

it('S13 hides whole schemas with dotless patterns', function () {
    $hidden = new HiddenObjects(['pg_toast*', 'audit']);

    expect($hidden->isHidden('pg_toast'))->toBeTrue()
        ->and($hidden->isHidden('pg_toast_temp_1'))->toBeTrue()
        ->and($hidden->isHidden('audit'))->toBeTrue()
        ->and($hidden->isHidden('audit', 'events'))->toBeTrue()
        ->and($hidden->isHidden('pg_toast', 'pg_toast_1234'))->toBeTrue()
        ->and($hidden->isHidden('public'))->toBeFalse()
        // A dotless pattern names a schema, never an object.
        ->and($hidden->isHidden('public', 'audit'))->toBeFalse();
});

it('S13 matches schema.name patterns against objects, each half on its own', function () {
    $hidden = new HiddenObjects(['*.password_reset*', 'public.personal_access_tokens', '*.dbx_*']);

    expect($hidden->isHidden('public', 'password_resets'))->toBeTrue()
        ->and($hidden->isHidden('app', 'password_reset_tokens'))->toBeTrue()
        ->and($hidden->isHidden('public', 'personal_access_tokens'))->toBeTrue()
        ->and($hidden->isHidden('tenant', 'personal_access_tokens'))->toBeFalse()
        ->and($hidden->isHidden('dbexplorer', 'dbx_audit_log'))->toBeTrue()
        ->and($hidden->isHidden('public', 'users'))->toBeFalse()
        // Object patterns leave the schema itself visible.
        ->and($hidden->isHidden('public'))->toBeFalse()
        // The halves are matched separately, so a dot in a name can't shift them.
        ->and($hidden->isHidden('public.personal_access_tokens', 'x'))->toBeFalse();
});

it('S13 hides the schema itself for schema.* patterns', function () {
    $hidden = new HiddenObjects(['dbexplorer.*', 'tenant_*.*']);

    expect($hidden->isHidden('dbexplorer'))->toBeTrue()
        ->and($hidden->isHidden('dbexplorer', 'dbx_runs'))->toBeTrue()
        ->and($hidden->isHidden('tenant_42'))->toBeTrue()
        ->and($hidden->isHidden('tenant'))->toBeFalse()
        ->and($hidden->isHidden('public'))->toBeFalse();
});

it('S13 matches case-sensitively, like quoted identifiers', function () {
    $hidden = new HiddenObjects(['public.Secrets', 'Audit']);

    expect($hidden->isHidden('public', 'Secrets'))->toBeTrue()
        ->and($hidden->isHidden('public', 'secrets'))->toBeFalse()
        ->and($hidden->isHidden('PUBLIC', 'Secrets'))->toBeFalse()
        ->and($hidden->isHidden('Audit'))->toBeTrue()
        ->and($hidden->isHidden('audit'))->toBeFalse();
});

it('treats glob metacharacters other than * literally', function () {
    $hidden = new HiddenObjects(['public.a?c', 'public.[x]', 'public.d.e']);

    expect($hidden->isHidden('public', 'abc'))->toBeFalse()
        ->and($hidden->isHidden('public', 'a?c'))->toBeTrue()
        ->and($hidden->isHidden('public', 'x'))->toBeFalse()
        ->and($hidden->isHidden('public', '[x]'))->toBeTrue()
        // Split at the first dot: the name half may contain dots.
        ->and($hidden->isHidden('public', 'd.e'))->toBeTrue();
});

it('reads dbexplorer.hidden by default and ignores non-string patterns', function () {
    config(['dbexplorer.hidden' => ['fixture.secrets', 42, null, '', ['nested']]]);

    $hidden = new HiddenObjects;

    expect($hidden->isHidden('fixture', 'secrets'))->toBeTrue()
        ->and($hidden->isHidden('fixture', 'users'))->toBeFalse()
        ->and($hidden->isHidden('fixture'))->toBeFalse()
        ->and((new HiddenObjects([]))->isHidden('fixture', 'secrets'))->toBeFalse();
});

it('ships defaults that hide DbExplorer tables and toast schemas', function () {
    $hidden = new HiddenObjects;

    expect($hidden->isHidden('public', 'dbx_audit_log'))->toBeTrue()
        ->and($hidden->isHidden('dbexplorer'))->toBeTrue()
        ->and($hidden->isHidden('pg_toast'))->toBeTrue()
        ->and($hidden->isHidden('public', 'personal_access_tokens'))->toBeTrue()
        ->and($hidden->isHidden('public', 'password_reset_tokens'))->toBeTrue()
        ->and($hidden->isHidden('public', 'users'))->toBeFalse();
});
