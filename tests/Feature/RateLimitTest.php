<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

function limitFor(Request $request): Limit
{
    $limiter = RateLimiter::limiter('dbexplorer');

    expect($limiter)->not->toBeNull();

    return $limiter($request);
}

function requestFrom(string $ip, ?User $user = null): Request
{
    $request = Request::create('/dbexplorer/api/me', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

it('S6 defines the dbexplorer limiter with the configured per-minute limit', function () {
    config(['dbexplorer.limits.queries_per_minute' => 5]);

    expect(limitFor(requestFrom('10.0.0.1', makeUser(7))))
        ->maxAttempts->toBe(5)
        ->key->toBe('user:7');
});

it('S6 keys the limiter per user so one IP cannot share or reset a budget', function () {
    $alice = limitFor(requestFrom('10.0.0.1', makeUser(1)));
    $bob = limitFor(requestFrom('10.0.0.1', makeUser(2)));
    $aliceElsewhere = limitFor(requestFrom('10.9.9.9', makeUser(1)));

    expect($alice->key)->not->toBe($bob->key)
        ->and($alice->key)->toBe($aliceElsewhere->key);
});

it('S6 falls back to the client IP for guests', function () {
    expect(limitFor(requestFrom('198.51.100.23'))->key)->toBe('ip:198.51.100.23');
});

it('S6 answers 429 once a route using the limiter is over budget', function () {
    config(['dbexplorer.limits.queries_per_minute' => 2]);
    Route::get('dbx-throttled', fn (): string => 'ok')->middleware('throttle:dbexplorer');

    $this->get('/dbx-throttled')->assertOk();
    $this->get('/dbx-throttled')->assertOk();
    $this->get('/dbx-throttled')->assertStatus(429);
});

it('S6 does not define the limiter while the package is disabled', function () {
    $this->bootWith(['dbexplorer.enabled' => false]);

    expect(RateLimiter::limiter('dbexplorer'))->toBeNull();
});
