<?php

declare(strict_types=1);

use Pest\ArchPresets\Php;

// Arch presets need Pest 3+. Pest 1 has no arch() and Pest 2 has no presets, so those lanes skip this file.
if (! function_exists('arch') || ! class_exists(Php::class)) {
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
