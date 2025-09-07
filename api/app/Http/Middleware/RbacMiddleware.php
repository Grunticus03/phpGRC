<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4 RBAC placeholder.
 * No enforcement. Tags request and passes through.
 * Route roles may be declared but are not enforced this phase.
 */
final class RbacMiddleware
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Tag for downstream awareness only.
        $enabled = (bool) config('core.rbac.enabled', false);
        $request->attributes->set('rbac_enabled', $enabled);

        // Future phases will evaluate declared roles.
        // $route = $request->route();
        // $declared = $route?->defaults['roles'] ?? [];

        return $next($request);
    }
}
