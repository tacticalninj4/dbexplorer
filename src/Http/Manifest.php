<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http;

use RuntimeException;

/**
 * Reads the Vite manifest of the compiled SPA in the package's `dist/`.
 */
class Manifest
{
    public const ENTRY = 'resources/js/app.ts';

    protected string $dist;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $chunks = null;

    public function __construct(?string $dist = null)
    {
        $this->dist = $dist ?? dirname(__DIR__, 2).'/dist';
    }

    /**
     * The entry script, the stylesheets it needs (its own and those of the
     * chunks it imports statically), and those chunks to modulepreload.
     *
     * @return array{script: string, css: list<string>, preload: list<string>}
     */
    public function entry(): array
    {
        $chunks = $this->chunks();
        $css = [];
        $preload = [];
        $pending = [self::ENTRY];
        $seen = [];

        while ($pending !== []) {
            $key = array_shift($pending);

            if (isset($seen[$key]) || ! isset($chunks[$key])) {
                continue;
            }

            $seen[$key] = true;
            $css = array_merge($css, $this->strings($chunks[$key]['css'] ?? []));
            $pending = array_merge($pending, $this->strings($chunks[$key]['imports'] ?? []));

            if ($key !== self::ENTRY && is_string($chunks[$key]['file'] ?? null)) {
                $preload[] = $chunks[$key]['file'];
            }
        }

        $script = $chunks[self::ENTRY]['file'] ?? null;

        if (! is_string($script)) {
            throw new RuntimeException("DbExplorer's asset manifest has no [".self::ENTRY.'] entry. Rebuild the assets with `npm run build`.');
        }

        return ['script' => $script, 'css' => array_values(array_unique($css)), 'preload' => $preload];
    }

    /**
     * The absolute path of a built file, or null unless the manifest lists it.
     * Only listed files are ever served, so request paths can't escape `dist/`.
     */
    public function path(string $file): ?string
    {
        foreach ($this->chunks() as $chunk) {
            $files = array_merge(
                [$chunk['file'] ?? null],
                $this->strings($chunk['css'] ?? []),
                $this->strings($chunk['assets'] ?? []),
            );

            if (in_array($file, $files, true)) {
                return $this->dist.'/'.$file;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function chunks(): array
    {
        if ($this->chunks !== null) {
            return $this->chunks;
        }

        $path = $this->dist.'/.vite/manifest.json';

        if (! is_file($path)) {
            throw new RuntimeException("DbExplorer's compiled assets are missing: [{$path}] was not found. Reinstall the package, or run `npm run build` in a development checkout.");
        }

        $chunks = json_decode((string) file_get_contents($path), true);

        if (! is_array($chunks)) {
            throw new RuntimeException("DbExplorer's asset manifest [{$path}] is not valid JSON.");
        }

        return $this->chunks = array_filter($chunks, 'is_array');
    }

    /**
     * @return list<string>
     */
    protected function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
