<?php

declare(strict_types=1);

namespace TacticalNinj4\DbExplorer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticalNinj4\DbExplorer\DbExplorer;

class ResolveRole
{
    public const LABEL = 'dbexplorer.label';

    public const ROLE = 'dbexplorer.role';

    public const CONNECTION = 'dbexplorer.connection';

    public function __construct(protected DbExplorer $explorer) {}

    /**
     * For routes with a `{label}` parameter, resolve the user's role and the
     * Laravel connection once, and keep both on the request attributes.
     * Unknown labels are a 404 so the allowlist isn't probed; no role is a 403.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $label = $request->route('label');

        if ($label === null) {
            return $next($request);
        }

        abort_unless(is_string($label) && $this->explorer->hasConnection($label), 404);

        $role = $this->explorer->roleFor($request->user(), $label);

        abort_if($role === null, 403);

        $request->attributes->add([
            self::LABEL => $label,
            self::ROLE => $role,
            self::CONNECTION => $this->explorer->connectionFor($label, $role),
        ]);

        return $next($request);
    }
}
