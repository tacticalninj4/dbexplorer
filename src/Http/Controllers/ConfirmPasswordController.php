<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use TacticalNinj4\DbExplorer\Facades\DbExplorer;
use TacticalNinj4\DbExplorer\Security\PasswordConfirmation;

class ConfirmPasswordController
{
    /**
     * Re-confirm the current user's password (S5): 204 on success, 422 with a
     * generic message otherwise. Every attempt is audited; the password never is.
     */
    public function __invoke(Request $request, PasswordConfirmation $confirmation): Response
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable, 401);

        $valid = Validator::make($request->only('password'), [
            'password' => ['required', 'string', 'max:1024'],
        ])->passes();

        $password = $request->input('password');

        if ($valid && is_string($password) && $this->check($user, $password)) {
            $confirmation->confirm($request);

            DbExplorer::audit(['action' => 'password_confirm', 'outcome' => 'success']);

            return new Response('', 204);
        }

        DbExplorer::audit(['action' => 'password_confirm', 'outcome' => 'failure']);

        throw ValidationException::withMessages(['password' => 'The provided password is incorrect.']);
    }

    /**
     * Check through the guard's user provider rather than a raw Hash::check(),
     * so custom providers (LDAP, other hashes, ...) decide. Falls back to the
     * hasher for guards without a provider. A user without a stored password
     * (e.g. SSO-only) never passes.
     */
    protected function check(Authenticatable $user, string $password): bool
    {
        // The default driver is the guard that authenticated this request.
        $auth = app(AuthManager::class);
        $provider = config('auth.guards.'.$auth->getDefaultDriver().'.provider');
        $provider = $auth->createUserProvider(is_string($provider) ? $provider : null);

        if ($provider !== null) {
            return $provider->validateCredentials($user, ['password' => $password]);
        }

        $hashed = (string) $user->getAuthPassword();

        return $hashed !== '' && Hash::check($password, $hashed);
    }
}
