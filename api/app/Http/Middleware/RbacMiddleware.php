<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4 placeholder middleware for RBAC.
 * Behavior:
 * - If core.rbac.enabled === false → no-op passthrough.
 * - Otherwise, leaves authorization to future policy checks.
 * - Provides scaffolding to read required roles from route/attributes.
 *
 * No persistence. No real enforcement in Phase 4.
 */
final class RbacMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Echo-only phase: bypass when disabled.
        if (! (bool) config('core.rbac.enabled', true)) {
            // Optionally tag request for downstream awareness.
            $request->attributes->set('rbac_enabled', false);
            return $next($request);
        }

        // Phase 4: do not enforce. Tag request and pass through.
        $request->attributes->set('rbac_enabled', true);

        // Future: read required roles from route/action attributes.
        // $required = $request->route()->defaults['roles'] ?? [];

        return $next($request);
    }
}
