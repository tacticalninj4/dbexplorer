<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class IpAllowlist
{
    /**
     * Reject clients outside `dbexplorer.ip_allowlist`. An empty list allows any IP.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowlist = (array) config('dbexplorer.ip_allowlist', []);

        if ($allowlist !== []) {
            // Non-string entries never match, so a malformed list fails closed.
            $ranges = array_values(array_filter($allowlist, 'is_string'));

            abort_unless(IpUtils::checkIp((string) $request->ip(), $ranges), 403);
        }

        return $next($request);
    }
}
