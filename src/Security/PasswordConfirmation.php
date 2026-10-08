<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Security;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Password re-confirmation for destructive actions (S5).
 *
 * Shares Laravel's own session key and `auth.password_timeout`, the same ones
 * the `password.confirm` middleware reads on Laravel 9–13, so a confirmation
 * made in the host app counts here and the reverse.
 */
class PasswordConfirmation
{
    /** The session key Laravel's RequirePassword middleware reads. */
    public const SESSION_KEY = 'auth.password_confirmed_at';

    /** Laravel's default when `auth.password_timeout` is not set. */
    public const DEFAULT_TIMEOUT = 10800;

    /**
     * Whether the user confirmed their password within `auth.password_timeout`
     * seconds. Always true while `dbexplorer.confirm_password` is false.
     */
    public function isRecent(Request $request): bool
    {
        if (! (bool) config('dbexplorer.confirm_password', true)) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        $confirmedAt = $request->session()->get(self::SESSION_KEY, 0);
        $confirmedAt = is_numeric($confirmedAt) ? (int) $confirmedAt : 0;

        return Date::now()->getTimestamp() - $confirmedAt <= $this->timeout();
    }

    /**
     * Record a successful confirmation, as Laravel's Session::passwordConfirmed()
     * does. The timestamp comes from Date so tests can travel in time.
     */
    public function confirm(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, Date::now()->getTimestamp());
    }

    /**
     * Stop the request with Laravel's JSON 423 response unless the password
     * was confirmed recently. Call it only for destructive actions.
     *
     * @throws HttpResponseException
     */
    public function ensureConfirmed(Request $request): void
    {
        if (! $this->isRecent($request)) {
            throw new HttpResponseException(new JsonResponse(['message' => 'Password confirmation required.'], 423));
        }
    }

    protected function timeout(): int
    {
        $timeout = config('auth.password_timeout');

        // Same fallback as RequirePassword: an unset or zero timeout means the default.
        return is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : self::DEFAULT_TIMEOUT;
    }
}
