<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// The README's configuration and command tables are the reference hosts
// configure from: they must list exactly what the code has.

/**
 * The first-column names of the README table that starts with $header,
 * with `{a,b}` groups expanded.
 *
 * @return list<string>
 */
function readmeTableNames(string $header): array
{
    $readme = (string) file_get_contents(__DIR__.'/../../README.md');
    $table = substr($readme, (int) strpos($readme, $header));
    $table = substr($table, 0, (int) strpos($table, "\n\n"));

    preg_match_all('/^\| `([^`]+)`/m', $table, $matches);

    $names = [];

    foreach ($matches[1] as $name) {
        $name = (string) preg_replace('/ \[.*$/', '', $name);

        if (preg_match('/^(.*)\{([^}]+)\}(.*)$/', $name, $group) === 1) {
            foreach (explode(',', $group[2]) as $part) {
                $names[] = $group[1].$part.$group[3];
            }
        } else {
            $names[] = $name;
        }
    }

    sort($names);

    return $names;
}

/**
 * Leaf keys of a config array, dotted. Lists and `connections` are leaves.
 *
 * @param  array<array-key, mixed>  $config
 * @return list<string>
 */
function configLeafKeys(array $config, string $prefix = ''): array
{
    $keys = [];

    foreach ($config as $key => $value) {
        $name = $prefix.$key;

        if (is_array($value) && $value !== [] && array_values($value) !== $value && $name !== 'connections') {
            $keys = array_merge($keys, configLeafKeys($value, $name.'.'));
        } else {
            $keys[] = $name;
        }
    }

    return $keys;
}

it('documents every config key in the README, and no others', function () {
    $keys = configLeafKeys(require __DIR__.'/../../config/dbexplorer.php');
    sort($keys);

    expect(readmeTableNames('| Key | Default | Description |'))->toBe($keys);
});

it('documents every dbx command in the README, and no others', function () {
    $commands = array_values(array_filter(array_keys(Artisan::all()), fn (string $name): bool => str_starts_with($name, 'dbx:')));
    sort($commands);

    expect(readmeTableNames('| Command | What it does |'))->toBe($commands);
});
