<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool hasConnection(string $label)
 * @method static string connectionFor(string $label, string $role)
 *
 * @see \TacticalNinj4\DbExplorer\DbExplorer
 */
class DbExplorer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \TacticalNinj4\DbExplorer\DbExplorer::class;
    }
}
