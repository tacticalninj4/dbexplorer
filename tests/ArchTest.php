<?php

declare(strict_types=1);

if (! function_exists('arch')) {
    return;
}

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('TacticalNinj4\DbExplorer')
    ->toUseStrictTypes();
