<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use TacticalNinj4\DbExplorer\Models\AuditEntry;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;
use Workbench\App\Models\User;

function userWithPassword(?string $password = 'correct-horse-battery-staple', int $id = 1): User
{
    $user = makeUser($id);
    $user->forceFill(['password' => $password === null ? null : Hash::make($password)]);

    return $user;
}

beforeEach(function () {
    // A gate rather than the local environment, which would turn CSRF checks on.
    Gate::define('viewDbExplorer', fn (): bool => true);

    Artisan::call('migrate');

    // Stands in for a destructive endpoint (M2.2+), which calls ensureConfirmed() first.
    Route::post('/dbx-destructive', function (Request $request, PasswordConfirmation $confirmation): string {
        $confirmation->ensureConfirmed($request);

        return 'done';
    })->middleware('web');
});

it('S5 answers 423 with Laravel\'s JSON body when the confirmation is stale', function () {
    $this->actingAs(userWithPassword())
        ->postJson('/dbx-destructive')
        ->assertStatus(423)
        ->assertExactJson(['message' => 'Password confirmation required.']);
});

it('S5 rejects a wrong password with a generic 422 and audits the failure', function () {
    $this->actingAs(userWithPassword())
        ->postJson('/dbexplorer/api/confirm-password', ['password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password', ['The provided password is incorrect.']);

    $entry = AuditEntry::query()->sole();

    expect($entry->action)->toBe('password_confirm')
        ->and($entry->outcome)->toBe('failure')
        ->and($entry->user_id)->toBe('1');

    $this->postJson('/dbx-destructive')->assertStatus(423);
});

it('S5 rejects a missing or oversized password and audits it', function (array $body) {
    $this->actingAs(userWithPassword())
        ->postJson('/dbexplorer/api/confirm-password', $body)
        ->assertStatus(422)
        ->assertJsonPath('errors.password', ['The provided password is incorrect.']);

    expect(AuditEntry::query()->sole()->outcome)->toBe('failure');
})->with([
    'missing' => [[]],
    'not a string' => [['password' => ['x']]],
    'over 1024 characters' => [['password' => str_repeat('a', 1025)]],
]);

it('S5 accepts the right password with 204, audits it, and lets the destructive request through', function () {
    $this->actingAs(userWithPassword())
        ->postJson('/dbexplorer/api/confirm-password', ['password' => 'correct-horse-battery-staple'])
        ->assertNoContent();

    expect(AuditEntry::query()->sole())
        ->action->toBe('password_confirm')
        ->outcome->toBe('success');

    $this->postJson('/dbx-destructive')->assertOk()->assertSee('done');
});

it('S5 requires confirmation again once auth.password_timeout has passed', function () {
    // Frozen, so a second ticking over between the confirmation and travel()
    // can't add to the elapsed time.
    $this->freezeTime();
    config(['auth.password_timeout' => 60]);

    $this->actingAs(userWithPassword())->postJson('/dbexplorer/api/confirm-password', ['password' => 'correct-horse-battery-staple'])->assertNoContent();

    $this->travel(60)->seconds();
    $this->postJson('/dbx-destructive')->assertOk();

    $this->travel(1)->seconds();
    $this->postJson('/dbx-destructive')->assertStatus(423);
});

it('S5 falls back to Laravel\'s 3-hour default timeout', function () {
    // Frozen, so a second ticking over between the confirmation and travel()
    // can't add to the elapsed time.
    $this->freezeTime();
    config(['auth.password_timeout' => null]);

    $this->actingAs(userWithPassword())->postJson('/dbexplorer/api/confirm-password', ['password' => 'correct-horse-battery-staple'])->assertNoContent();

    $this->travel(10800)->seconds();
    $this->postJson('/dbx-destructive')->assertOk();

    $this->travel(1)->seconds();
    $this->postJson('/dbx-destructive')->assertStatus(423);
});

it('S5 counts a confirmation made in the host app', function () {
    $this->actingAs(userWithPassword())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/dbx-destructive')
        ->assertOk();
});

it('S5 shares its confirmation with the host app\'s password.confirm middleware', function () {
    Route::post('/host-sensitive', fn (): string => 'host ok')->middleware(['web', RequirePassword::class]);

    $this->actingAs(userWithPassword());
    $this->postJson('/host-sensitive')->assertStatus(423);

    $this->postJson('/dbexplorer/api/confirm-password', ['password' => 'correct-horse-battery-staple'])->assertNoContent();
    $this->postJson('/host-sensitive')->assertOk()->assertSee('host ok');
});

it('S5 throttles confirmation attempts to 5 per minute per user', function () {
    $this->actingAs(userWithPassword());

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/dbexplorer/api/confirm-password', ['password' => 'guess-'.$i])->assertStatus(422);
    }

    $this->postJson('/dbexplorer/api/confirm-password', ['password' => 'correct-horse-battery-staple'])->assertStatus(429);
    $this->postJson('/dbx-destructive')->assertStatus(423);

    // Another user's budget is separate.
    $this->actingAs(userWithPassword('correct-horse-battery-staple', 2))
        ->postJson('/dbexplorer/api/confirm-password', ['password' => 'correct-horse-battery-staple'])
        ->assertNoContent();

    expect(AuditEntry::query()->count())->toBe(6);
});

it('S5 never writes the password to the audit log or its log channel', function () {
    config([
        'logging.channels.dbx_audit' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.default' => 'dbx_audit',
        'dbexplorer.audit.log_channel' => 'dbx_audit',
    ]);
    $wrong = 'wrong-but-sensitive-guess';

    $this->actingAs(userWithPassword());
    $this->postJson('/dbexplorer/api/confirm-password', ['password' => $wrong])->assertStatus(422);
    $this->postJson('/dbexplorer/api/confirm-password', ['password' => 'correct-horse-battery-staple'])->assertNoContent();

    /** @var TestHandler $handler */
    $handler = Log::channel('dbx_audit')->getLogger()->getHandlers()[0];
    $logged = json_encode($handler->getRecords());
    $stored = json_encode(AuditEntry::query()->get()->toArray());

    expect($handler->getRecords())->toHaveCount(2)
        ->and(AuditEntry::query()->count())->toBe(2)
        ->and($logged)->not->toContain($wrong)->not->toContain('correct-horse-battery-staple')
        ->and($stored)->not->toContain($wrong)->not->toContain('correct-horse-battery-staple');
});

it('S5 cannot be confirmed by a user without a password, such as an SSO-only account', function (?string $stored) {
    // Raw, past the model's `hashed` cast: this is what the users table holds.
    $user = makeUser(1);
    $user->setRawAttributes(['password' => $stored] + $user->getAttributes());

    // The hasher never sees the empty hash: on Laravel 10+ it throws
    // "This password does not use the Bcrypt algorithm" (a 500).
    Hash::shouldReceive('check')->never();

    $this->actingAs($user)
        ->postJson('/dbexplorer/api/confirm-password', ['password' => ''])
        ->assertStatus(422);

    $this->postJson('/dbexplorer/api/confirm-password', ['password' => 'anything'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password', ['The provided password is incorrect.']);

    $this->postJson('/dbx-destructive')->assertStatus(423);

    expect(AuditEntry::query()->pluck('outcome', 'id')->values()->all())->toBe(['failure', 'failure'])
        ->and(AuditEntry::query()->pluck('action')->unique()->all())->toBe(['password_confirm']);
})->with(['null' => [null], 'empty' => ['']]);

it('S5 skips confirmation entirely when dbexplorer.confirm_password is false', function () {
    config(['dbexplorer.confirm_password' => false]);

    $this->actingAs(userWithPassword(null))
        ->postJson('/dbx-destructive')
        ->assertOk();
});

it('S5 serves the confirm endpoint only through the package middleware', function () {
    $this->postJson('/dbexplorer/api/confirm-password', ['password' => 'correct-horse-battery-staple'])->assertStatus(401);

    expect(AuditEntry::query()->count())->toBe(0);
});
