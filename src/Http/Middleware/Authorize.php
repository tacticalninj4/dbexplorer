<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    /**
     * Allow the request through the host app's `viewDbExplorer` gate. Without
     * that gate, only the local environment is allowed in.
     *
     * The gate is looked up per request, so it doesn't matter whether the host
     * app defines it before or after this package boots.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = Gate::has('viewDbExplorer')
            ? Gate::forUser($request->user())->allows('viewDbExplorer')
            : app()->environment('local');

        abort_unless($allowed, 403);

        return $next($request);
    }
}
