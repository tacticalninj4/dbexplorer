<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TacticalNinj4\DbExplorer\Http\Manifest;

beforeEach(function () {
    app()['env'] = 'local';
});

function shellNonce(string $csp): string
{
    expect($csp)->toMatch("/script-src 'self' 'nonce-([A-Za-z0-9+\\/]+={0,2})'/");

    preg_match("/'nonce-([^']+)'/", $csp, $matches);

    return $matches[1];
}

it('S15 serves the shell with a strict CSP whose nonce is on every script tag', function () {
    $response = $this->actingAs(makeUser())->get('/dbexplorer')->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');
    $nonce = shellNonce($csp);
    $html = (string) $response->getContent();

    expect($csp)->toBe(implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}'",
        "style-src 'self'",
        "img-src 'self' data:",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
    ]));

    preg_match_all('/<script\b[^>]*>/', $html, $scripts);

    expect($scripts[0])->toHaveCount(2);

    foreach ($scripts[0] as $tag) {
        expect($tag)->toContain('nonce="'.$nonce.'"');
    }

    expect($csp)->not->toContain('unsafe-inline')
        ->and($csp)->not->toContain('unsafe-eval');
});

it('S15 uses a fresh nonce for every request', function () {
    $first = $this->actingAs(makeUser())->get('/dbexplorer');
    $second = $this->actingAs(makeUser())->get('/dbexplorer');

    expect(shellNonce((string) $first->headers->get('Content-Security-Policy')))
        ->not->toBe(shellNonce((string) $second->headers->get('Content-Security-Policy')));
});

it('S15 sends the security headers on shell, API, asset and error responses', function (string $uri) {
    $this->actingAs(makeUser())
        ->get($uri)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
})->with([
    'shell' => '/dbexplorer',
    'api' => '/dbexplorer/api/me',
    'asset' => fn (): string => '/dbexplorer/'.app(Manifest::class)->entry()['script'],
    'missing asset' => '/dbexplorer/assets/nope.js',
]);

it('S15 sends the security headers when the gate denies the request', function () {
    app()['env'] = 'production';

    $this->actingAs(makeUser())
        ->get('/dbexplorer')
        ->assertForbidden()
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('loads the compiled entry script and stylesheets from the manifest', function () {
    $entry = app(Manifest::class)->entry();

    $response = $this->actingAs(makeUser())->get('/dbexplorer')->assertOk();

    $response->assertSee('<div id="app"></div>', false)
        ->assertSee('<script type="module" src="/dbexplorer/'.$entry['script'].'"', false);

    expect($entry['css'])->not->toBeEmpty();

    foreach ($entry['css'] as $css) {
        $response->assertSee('<link rel="stylesheet" href="/dbexplorer/'.$css.'">', false);
    }
});

it('injects the base path, CSRF token and user name into window.DbExplorer', function () {
    $this->bootWith(['dbexplorer.path' => 'admin/db']);
    app()['env'] = 'local';

    $response = $this->actingAs(makeUser())->get('/admin/db/tables/users')->assertOk();

    preg_match("/window\\.DbExplorer = JSON\\.parse\\('(.*)'\\);/", (string) $response->getContent(), $matches);

    expect($matches)->toHaveCount(2)
        ->and(json_decode((string) json_decode('"'.$matches[1].'"'), true))->toBe([
            'basePath' => '/admin/db',
            'csrfToken' => session()->token(),
            'user' => ['name' => 'Dev'],
        ]);
});

it('escapes user data in the inline script so it cannot close the tag', function () {
    $user = makeUser();
    $user->forceFill(['name' => '</script><script>alert(1)</script>']);

    $html = (string) $this->actingAs($user)->get('/dbexplorer')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')
        ->and(substr_count($html, '</script>'))->toBe(2);
});

it('serves the shell for client-side routes under the prefix', function (string $uri) {
    $this->actingAs(makeUser())->get($uri)->assertOk()->assertSee('<div id="app"></div>', false);
})->with(['/dbexplorer', '/dbexplorer/', '/dbexplorer/main/tables/users', '/dbexplorer/apis', '/dbexplorer/assetsx']);

it('does not answer unknown API routes with the shell', function (string $uri) {
    $this->actingAs(makeUser())
        ->getJson($uri)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertDontSee('<div id="app">', false);
})->with(['/dbexplorer/api', '/dbexplorer/api/nope', '/dbexplorer/api/main/nope', '/dbexplorer/assets']);

it('still routes API endpoints registered after the shell', function () {
    Route::middleware(Route::getRoutes()->getByName('dbexplorer.me')?->gatherMiddleware() ?? [])
        ->get('dbexplorer/api/later', fn (): array => ['later' => true]);

    $this->actingAs(makeUser())->getJson('/dbexplorer/api/later')->assertOk()->assertExactJson(['later' => true]);
});

it('redirects guests to the login page', function () {
    Route::get('login', fn (): string => 'login')->name('login');

    $this->get('/dbexplorer')->assertRedirect('/login');
});

it('fails with a clear message when the compiled assets are missing', function () {
    app()->instance(Manifest::class, new Manifest(sys_get_temp_dir().'/dbexplorer-missing-dist'));

    $this->withoutExceptionHandling()->actingAs(makeUser())->get('/dbexplorer');
})->throws(RuntimeException::class, "DbExplorer's compiled assets are missing");
