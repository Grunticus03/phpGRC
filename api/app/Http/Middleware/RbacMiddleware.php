<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RbacMiddleware
{
    /**
     * Placeholder: no enforcement in Phase 4.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // No-op while core.rbac.enabled is only a config key.
        return $next($request);
    }
}
