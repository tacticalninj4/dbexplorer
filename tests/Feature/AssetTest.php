<?php

declare(strict_types=1);

use TacticalNinj4\DbExplorer\Http\Manifest;

beforeEach(function () {
    app()['env'] = 'local';
});

it('serves the entry script with its type and an immutable cache header', function () {
    $script = app(Manifest::class)->entry()['script'];

    $response = $this->actingAs(makeUser())->get('/dbexplorer/'.$script)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/javascript; charset=utf-8')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))
        ->toBe(file_get_contents(dirname(__DIR__, 2).'/dist/'.$script));
});

it('serves the entry stylesheets as CSS', function () {
    foreach (app(Manifest::class)->entry()['css'] as $css) {
        $this->actingAs(makeUser())->get('/dbexplorer/'.$css)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=utf-8');
    }
});

it('serves only files listed in the manifest', function (string $uri) {
    $this->actingAs(makeUser())->get($uri)->assertNotFound();
})->with([
    'parent traversal' => '/dbexplorer/assets/../composer.json',
    'deep traversal' => '/dbexplorer/assets/../../composer.json',
    'encoded traversal' => '/dbexplorer/assets/%2e%2e/composer.json',
    'encoded slash traversal' => '/dbexplorer/assets/..%2fcomposer.json',
    'absolute path' => '/dbexplorer/assets//etc/passwd',
    'encoded absolute path' => '/dbexplorer/assets/%2Fetc%2Fpasswd',
    'unknown hashed name' => '/dbexplorer/assets/app-AAAAAAAA.js',
    'manifest' => '/dbexplorer/assets/../.vite/manifest.json',
    'manifest by name' => '/dbexplorer/assets/.vite/manifest.json',
    'source file' => '/dbexplorer/assets/resources/js/app.ts',
]);

it('answers paths outside assets/ with the shell, never a file', function (string $uri) {
    $this->actingAs(makeUser())->get($uri)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertDontSee('isEntry')
        ->assertDontSee('tacticalninj4/dbexplorer');
})->with(['/dbexplorer/.vite/manifest.json', '/dbexplorer/composer.json']);

it('only resolves paths for manifest files', function () {
    $manifest = app(Manifest::class);
    $script = $manifest->entry()['script'];

    expect($manifest->path($script))->toBe(dirname(__DIR__, 2).'/dist/'.$script)
        ->and($manifest->path('.vite/manifest.json'))->toBeNull()
        ->and($manifest->path('assets/../../composer.json'))->toBeNull()
        ->and($manifest->path(Manifest::ENTRY))->toBeNull();
});

it('keeps assets behind the gate', function () {
    app()['env'] = 'production';

    $this->actingAs(makeUser())->get('/dbexplorer/'.app(Manifest::class)->entry()['script'])->assertForbidden();
});
