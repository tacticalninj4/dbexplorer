<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use Workbench\App\Models\User;

beforeEach(function () {
    app()['env'] = 'local';

    config(['dbexplorer.connections' => [
        'main' => ['viewer' => 'pgsql_readonly', 'editor' => 'pgsql_editor', 'admin' => 'pgsql'],
        'staging' => ['viewer' => 'staging_readonly', 'admin' => 'staging'],
        'reporting' => ['viewer' => 'reporting'],
    ]]);
});

it('lists every allowlisted label as viewer when no role callback is registered', function () {
    $this->actingAs(makeUser())
        ->getJson('/dbexplorer/api/me')
        ->assertOk()
        ->assertExactJson([
            'user' => ['name' => 'Dev'],
            'connections' => [
                ['label' => 'main', 'role' => 'viewer'],
                ['label' => 'staging', 'role' => 'viewer'],
                ['label' => 'reporting', 'role' => 'viewer'],
            ],
            // Testbench's queue is sync (RunsTest covers the queue warnings).
            'warnings' => ['The queue driver is "sync": background work runs inside the request and blocks it until done.'],
        ]);
});

it('lists each label with the role the callback grants and omits labels with no role', function () {
    DbExplorer::roleUsing(fn (User $user, string $label): ?string => [
        'main' => 'editor',
        'staging' => 'admin',
    ][$label] ?? null);

    $this->actingAs(makeUser())
        ->getJson('/dbexplorer/api/me')
        ->assertOk()
        ->assertJsonPath('connections', [
            ['label' => 'main', 'role' => 'editor'],
            ['label' => 'staging', 'role' => 'admin'],
        ]);
});

it('returns no connections when the user has no role anywhere', function () {
    DbExplorer::roleUsing(fn (): ?string => null);

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/me')->assertOk()->assertJsonPath('connections', []);
});

it('rejects guests with 401', function () {
    $this->getJson('/dbexplorer/api/me')->assertUnauthorized();
});

it('S7 never sends connection config to the browser', function (string $uri) {
    $sentinels = [
        'host' => 'sentinel-host.internal',
        'port' => '54329',
        'database' => 'sentinel_database',
        'username' => 'sentinel_username',
        'password' => 'sentinel-p4ssw0rd',
    ];

    foreach (['pgsql_readonly', 'pgsql_editor', 'pgsql', 'staging_readonly', 'staging', 'reporting'] as $i => $name) {
        config(["database.connections.{$name}" => [
            'driver' => 'pgsql',
            'url' => "pgsql://sentinel_url_user:sentinel-url-secret@sentinel-url-host:5432/sentinel_url_db{$i}",
            'host' => $sentinels['host'],
            'port' => $sentinels['port'],
            'database' => $sentinels['database'],
            'username' => $sentinels['username'],
            'password' => $sentinels['password'],
        ]]);
    }

    $body = (string) $this->actingAs(makeUser())->get($uri)->assertOk()->getContent();

    expect($body)->not->toContain('sentinel')
        ->and($body)->not->toContain('54329')
        ->and($body)->not->toContain('pgsql_readonly')
        ->and($body)->not->toContain('staging_readonly');
})->with(['shell' => '/dbexplorer', 'client route' => '/dbexplorer/main/tables/users', 'api/me' => '/dbexplorer/api/me']);
