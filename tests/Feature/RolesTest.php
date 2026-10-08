<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use TacticalNinj4\DbExplorer\DbExplorer as Explorer;
use TacticalNinj4\DbExplorer\Exceptions\ConnectionNotAllowedException;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Http\Middleware\ResolveRole;
use Workbench\App\Models\User;

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
        'staging' => ['viewer' => 'staging_readonly', 'admin' => 'staging'],
    ]]);

    // A {label} route behind the package's own middleware stack, standing in for the label APIs.
    $route = Route::getRoutes()->getByName('dbexplorer.me');
    expect($route)->not->toBeNull();

    Route::middleware($route->gatherMiddleware())
        ->get('dbexplorer/api/{label}/whoami', fn (Request $request): JsonResponse => new JsonResponse([
            'label' => $request->attributes->get(ResolveRole::LABEL),
            'role' => $request->attributes->get(ResolveRole::ROLE),
            'connection' => $request->attributes->get(ResolveRole::CONNECTION),
        ]));
});

it('gives every user the viewer role when no callback is registered', function () {
    $this->actingAs(makeUser())
        ->getJson('/dbexplorer/api/main/whoami')
        ->assertOk()
        ->assertExactJson(['label' => 'main', 'role' => 'viewer', 'connection' => 'pgsql_readonly']);
});

it('resolves the role per label through the callback', function () {
    DbExplorer::roleUsing(fn (User $user, string $label): string => $label === 'staging' ? 'admin' : 'editor');

    $this->actingAs(makeUser())
        ->getJson('/dbexplorer/api/staging/whoami')
        ->assertExactJson(['label' => 'staging', 'role' => 'admin', 'connection' => 'staging']);

    $this->actingAs(makeUser())
        ->getJson('/dbexplorer/api/main/whoami')
        ->assertExactJson(['label' => 'main', 'role' => 'editor', 'connection' => 'pgsql_editor']);
});

it('passes the authenticated user and the label to the callback', function () {
    $seen = [];
    DbExplorer::roleUsing(function (User $user, string $label) use (&$seen): string {
        $seen[] = [$user->getAuthIdentifier(), $label];

        return 'viewer';
    });

    $this->actingAs(makeUser(42))->getJson('/dbexplorer/api/main/whoami')->assertOk();

    expect($seen)->toBe([[42, 'main']]);
});

it('resolves the role once per request', function () {
    $calls = 0;
    DbExplorer::roleUsing(function () use (&$calls): string {
        $calls++;

        return 'viewer';
    });

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/whoami')->assertOk();

    expect($calls)->toBe(1);
});

it('denies a label when the callback returns null', function () {
    DbExplorer::roleUsing(fn (User $user, string $label): ?string => $label === 'main' ? 'viewer' : null);

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/whoami')->assertOk();
    $this->actingAs(makeUser())->getJson('/dbexplorer/api/staging/whoami')->assertForbidden();
});

it('S7 answers 404 for a label that is not allowlisted, without asking the callback', function (string $label) {
    $calls = 0;
    DbExplorer::roleUsing(function () use (&$calls): string {
        $calls++;

        return 'admin';
    });

    $this->actingAs(makeUser())->getJson("/dbexplorer/api/{$label}/whoami")->assertNotFound();

    expect($calls)->toBe(0);
})->with([
    'unknown label' => 'reporting',
    'raw connection name' => 'pgsql',
]);

it('refuses a role the label has no connection for', function () {
    DbExplorer::roleUsing(fn (): string => 'editor');

    $this->withoutExceptionHandling()->actingAs(makeUser())->getJson('/dbexplorer/api/staging/whoami');
})->throws(ConnectionNotAllowedException::class, 'has no connection mapped for the [editor] role');

it('refuses a callback result that is not a role', function (mixed $result) {
    DbExplorer::roleUsing(fn (): mixed => $result);

    $this->withoutExceptionHandling()->actingAs(makeUser())->getJson('/dbexplorer/api/main/whoami');
})->with([
    'unknown role' => 'superuser',
    'wrong case' => 'Admin',
    'boolean' => true,
])->throws(UnexpectedValueException::class, 'must return one of [viewer, editor, admin] or null');

it('does not resolve a role on routes without a label', function () {
    DbExplorer::roleUsing(fn (): ?string => null);

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/me')->assertOk();
});

it('runs role resolution after the gate', function () {
    app()['env'] = 'production';
    $calls = 0;
    DbExplorer::roleUsing(function () use (&$calls): string {
        $calls++;

        return 'admin';
    });

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/main/whoami')->assertForbidden();

    expect($calls)->toBe(0);
});

it('forgets the callback on flushState', function () {
    DbExplorer::roleUsing(fn (): ?string => null);
    DbExplorer::flushState();

    expect(app(Explorer::class)->roleFor(makeUser(), 'main'))->toBe('viewer');
});
