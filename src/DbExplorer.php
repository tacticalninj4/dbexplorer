<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer;

use Closure;
use TacticalNinj4\DbExplorer\Exceptions\ConnectionNotAllowedException;
use UnexpectedValueException;

class DbExplorer
{
    protected ?Closure $roleResolver = null;

    /**
     * Register the callback that maps a user and connection label to a role.
     *
     * It receives `($user, string $label)` and returns 'viewer', 'editor',
     * 'admin', or null for no access to that label.
     */
    public function roleUsing(callable $callback): self
    {
        $this->roleResolver = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * The user's role on a label, or null when they have no access to it.
     * Without a registered callback every user is a viewer.
     *
     * @throws UnexpectedValueException when the callback returns something that isn't a role
     */
    public function roleFor(mixed $user, string $label): ?string
    {
        if ($this->roleResolver === null) {
            return Role::VIEWER;
        }

        $role = ($this->roleResolver)($user, $label);

        if ($role === null) {
            return null;
        }

        if (! is_string($role) || ! Role::isValid($role)) {
            throw new UnexpectedValueException(sprintf(
                'The DbExplorer role callback must return one of [%s] or null, got [%s].',
                implode(', ', Role::ALL),
                is_string($role) ? $role : get_debug_type($role),
            ));
        }

        return $role;
    }

    /**
     * Forget the registered role callback.
     */
    public function flushState(): void
    {
        $this->roleResolver = null;
    }

    /**
     * Whether the label is listed in `dbexplorer.connections`.
     */
    public function hasConnection(string $label): bool
    {
        return $this->rolesFor($label) !== null;
    }

    /**
     * Resolve an explorer label and role to a Laravel connection name.
     *
     * @throws ConnectionNotAllowedException
     */
    public function connectionFor(string $label, string $role): string
    {
        $roles = $this->rolesFor($label);

        if ($roles === null) {
            throw ConnectionNotAllowedException::unknownLabel($label);
        }

        $connection = $roles[$role] ?? null;

        if (! is_string($connection) || $connection === '') {
            throw ConnectionNotAllowedException::missingRole($label, $role);
        }

        return $connection;
    }

    /**
     * The role => connection map for a label. Keys are matched exactly, never
     * as dot-notation paths, so a label can't reach into nested config.
     *
     * @return array<mixed>|null
     */
    protected function rolesFor(string $label): ?array
    {
        $connections = config('dbexplorer.connections');

        if (! is_array($connections) || ! is_array($connections[$label] ?? null)) {
            return null;
        }

        return $connections[$label];
    }
}
