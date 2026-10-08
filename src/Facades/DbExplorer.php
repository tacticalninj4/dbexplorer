<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \TacticalNinj4\DbExplorer\DbExplorer roleUsing(callable $callback)
 * @method static string|null roleFor(mixed $user, string $label)
 * @method static void flushState()
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
