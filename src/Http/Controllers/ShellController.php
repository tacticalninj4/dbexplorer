<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use TacticalNinj4\DbExplorer\Http\Manifest;
use TacticalNinj4\DbExplorer\Http\Middleware\ContentSecurityPolicy;

class ShellController
{
    /**
     * Render the SPA shell for any non-API URL under the prefix. The page gets
     * only the mount path, the CSRF token, the user's name and an opaque
     * per-user key that scopes what the SPA keeps in localStorage (so the
     * next user of a shared browser doesn't get it): never any connection
     * config (S7).
     */
    public function __invoke(Request $request, Manifest $manifest): View
    {
        // The shell route's own URL honours the configured domain and prefix
        // and any subdirectory the host app is served from.
        $basePath = rtrim((string) parse_url(route('dbexplorer.shell'), PHP_URL_PATH), '/');
        $name = data_get($request->user(), 'name');
        $id = $request->user()?->getAuthIdentifier();

        return view('dbexplorer::app', [
            'basePath' => $basePath,
            'entry' => $manifest->entry(),
            'nonce' => (string) $request->attributes->get(ContentSecurityPolicy::NONCE),
            'config' => [
                'basePath' => $basePath,
                'csrfToken' => $request->hasSession() ? (string) $request->session()->token() : '',
                'user' => [
                    'name' => is_string($name) ? $name : '',
                    'storageKey' => is_scalar($id) ? substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 16) : '',
                ],
            ],
        ]);
    }
}
