<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use TacticalNinj4\DbExplorer\Http\Middleware\Authorize;
use TacticalNinj4\DbExplorer\Http\Middleware\IpAllowlist;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use Workbench\App\Models\User;

// S1: disabled by default, and disabled means not registered.

it('S1 registers the routes when enabled', function () {
    app()['env'] = 'local';

    expect(Route::has('dbexplorer.ping'))->toBeTrue();

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/ping')->assertOk()->assertExactJson(['ok' => true]);
});

it('S1 registers no routes when disabled', function () {
    $this->bootWith(['dbexplorer.enabled' => false]);
    app()['env'] = 'local';

    expect(Route::has('dbexplorer.ping'))->toBeFalse();

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/ping')->assertNotFound();
});

// S2: the viewDbExplorer gate, or local-only without one.

it('S2 allows the local environment when no gate is defined', function () {
    app()['env'] = 'local';

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/ping')->assertOk();
});

it('S2 denies everyone outside local when no gate is defined', function (string $env) {
    app()['env'] = $env;

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/ping')->assertForbidden();
})->with(['production', 'staging', 'testing']);

it('S2 respects a host gate that allows the user', function () {
    app()['env'] = 'production';
    Gate::define('viewDbExplorer', fn (User $user): bool => $user->email === 'dba@example.com');

    $this->actingAs(makeUser(1, 'dba@example.com'))->getJson('/dbexplorer/api/ping')->assertOk();
});

it('S2 respects a host gate that denies the user, even in local', function () {
    app()['env'] = 'local';
    Gate::define('viewDbExplorer', fn (User $user): bool => $user->email === 'dba@example.com');

    $this->actingAs(makeUser(2, 'intern@example.com'))->getJson('/dbexplorer/api/ping')->assertForbidden();
});

it('S2 rejects unauthenticated requests', function () {
    app()['env'] = 'local';

    $this->getJson('/dbexplorer/api/ping')->assertUnauthorized();
});

it('S2 does not let guests through a gate when auth middleware is removed', function () {
    $this->bootWith(['dbexplorer.middleware' => []]);
    app()['env'] = 'production';
    Gate::define('viewDbExplorer', fn (User $user): bool => true);

    $this->getJson('/dbexplorer/api/ping')->assertForbidden();
});

// S3: prefix, domain and middleware are configurable.

it('S3 serves the routes under a custom prefix only', function () {
    $this->bootWith(['dbexplorer.path' => 'admin/database']);
    app()['env'] = 'local';

    $this->actingAs(makeUser())->getJson('/admin/database/api/ping')->assertOk();
    $this->actingAs(makeUser())->getJson('/dbexplorer/api/ping')->assertNotFound();
});

it('S3 serves the routes on the configured domain only', function () {
    $this->bootWith(['dbexplorer.domain' => 'db.example.com']);
    app()['env'] = 'local';

    $this->actingAs(makeUser())->getJson('http://db.example.com/dbexplorer/api/ping')->assertOk();
    $this->actingAs(makeUser())->getJson('http://www.example.com/dbexplorer/api/ping')->assertNotFound();
});

it('S3 applies the configured middleware stack in order', function () {
    $this->bootWith(['dbexplorer.middleware' => ['web', 'auth:web', 'verified']]);

    expect(Route::getRoutes()->getByName('dbexplorer.ping')?->gatherMiddleware())->toBe([
        IpAllowlist::class,
        'web',
        'auth:web',
        'verified',
        Authorize::class,
        ResolveRole::class,
    ]);
});

it('S3 runs custom middleware that can reject the request', function () {
    $this->bootWith(['dbexplorer.middleware' => ['web', 'auth', 'deny-all']]);
    Route::aliasMiddleware('deny-all', fn () => abort(418));
    app()['env'] = 'local';

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/ping')->assertStatus(418);
});

// S4: optional IP / CIDR allowlist.

it('S4 allows any IP when the allowlist is empty', function () {
    app()['env'] = 'local';

    $this->actingAs(makeUser())
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
        ->getJson('/dbexplorer/api/ping')
        ->assertOk();
});

it('S4 allows IPs inside the allowlist', function (string $ip) {
    app()['env'] = 'local';
    config(['dbexplorer.ip_allowlist' => ['10.0.0.0/8', '203.0.113.7', '2001:db8::/32']]);

    $this->actingAs(makeUser())
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->getJson('/dbexplorer/api/ping')
        ->assertOk();
})->with(['10.1.2.3', '203.0.113.7', '2001:db8::1']);

it('S4 rejects IPs outside the allowlist', function (string $ip) {
    app()['env'] = 'local';
    config(['dbexplorer.ip_allowlist' => ['10.0.0.0/8', '203.0.113.7', '2001:db8::/32']]);

    $this->actingAs(makeUser())
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->getJson('/dbexplorer/api/ping')
        ->assertForbidden();
})->with(['11.0.0.1', '203.0.113.8', '2001:db9::1', '127.0.0.1']);

it('S4 rejects a disallowed IP before authentication runs', function () {
    config(['dbexplorer.ip_allowlist' => ['10.0.0.0/8']]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
        ->getJson('/dbexplorer/api/ping')
        ->assertForbidden();
});

it('S4 fails closed when the allowlist has no usable entries', function () {
    app()['env'] = 'local';
    config(['dbexplorer.ip_allowlist' => [null, ['10.0.0.0/8']]]);

    $this->actingAs(makeUser())
        ->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->getJson('/dbexplorer/api/ping')
        ->assertForbidden();
});
