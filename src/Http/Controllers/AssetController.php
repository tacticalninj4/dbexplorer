<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use TacticalNinj4\DbExplorer\Http\Manifest;

class AssetController
{
    protected const TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    /**
     * Serve a compiled file from `dist/assets`. Only files listed in the Vite
     * manifest are served; anything else, including the manifest, is a 404.
     *
     * Filenames are content-hashed, so the response can be cached forever. It
     * is `private` because it passes through the session middleware like
     * every other DbExplorer route.
     */
    public function __invoke(Manifest $manifest, string $path): BinaryFileResponse
    {
        $file = $manifest->path('assets/'.$path);

        abort_if($file === null || ! is_file($file), 404);

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return new BinaryFileResponse($file, 200, [
            'Content-Type' => self::TYPES[$extension] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ], false);
    }
}
