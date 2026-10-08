<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \TacticalNinj4\DbExplorer\DbExplorer
 */
class DbExplorer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \TacticalNinj4\DbExplorer\DbExplorer::class;
    }
}
