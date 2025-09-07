<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC middleware with route-level role enforcement.
 *
 * Behavior
 * - If config('core.rbac.enabled') is false → passthrough.
 * - If no roles are declared on the route → passthrough.
 * - Else require the authenticated user to have ≥1 of the declared roles.
 *
 * Declare roles on routes with ->defaults('roles', ['Admin', 'Auditor'])
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
        $enabled = (bool) config('core.rbac.enabled', false);
        if (! $enabled) {
            $request->attributes->set('rbac_enabled', false);
            return $next($request);
        }

        $request->attributes->set('rbac_enabled', true);

        $route = $request->route();
        if ($route === null) {
            return $next($request);
        }

        /** @var array<string,mixed> $action */
        $action = $route->getAction();

        /** @var mixed $declared */
        $declared = $action['roles'] ?? ($route->defaults['roles'] ?? []);

        $requiredRoles = is_string($declared) ? [$declared] : (array) $declared;
        if ($requiredRoles === []) {
            return $next($request);
        }

        $authUser = $request->user();
        if (! $authUser instanceof User) {
            abort(401);
        }

        if ($authUser->hasAnyRole($requiredRoles)) {
            return $next($request);
        }

        abort(403);
    }
}