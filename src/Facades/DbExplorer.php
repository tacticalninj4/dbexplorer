<?php

declare(strict_types=1);

namespace njrdevs\DbExplorer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \njrdevs\DbExplorer\DbExplorer
 */
class DbExplorer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \njrdevs\DbExplorer\DbExplorer::class;
    }
}
