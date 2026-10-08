<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer;

use TacticalNinj4\DbExplorer\Exceptions\ConnectionNotAllowedException;

class DbExplorer
{
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
