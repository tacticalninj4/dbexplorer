<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Exceptions;

use InvalidArgumentException;

class ConnectionNotAllowedException extends InvalidArgumentException
{
    public static function unknownLabel(string $label): self
    {
        return new self("The connection label [{$label}] is not listed in dbexplorer.connections.");
    }

    public static function missingRole(string $label, string $role): self
    {
        return new self("The connection label [{$label}] has no connection mapped for the [{$role}] role.");
    }
}
