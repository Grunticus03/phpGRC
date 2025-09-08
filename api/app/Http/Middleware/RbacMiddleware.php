<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC middleware.
 * - If core.rbac.enabled = false → passthrough.
 * - If no roles declared on route → passthrough.
 * - If roles declared:
 *     • Anonymous user → passthrough (Phase 4 behavior).
 *     • Authenticated without required role → 403.
 *     • Authenticated with role → allow.
 *
 * Declare roles on routes with: ->defaults('roles', ['Admin'])
 */
final class RbacMiddleware
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) config('core.rbac.enabled', false);
        $request->attributes->set('rbac_enabled', $enabled);

        if (! $enabled) {
            return $next($request);
        }

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

        $user = $request->user();

        // Phase 4: anonymous requests pass through.
        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->hasAnyRole($requiredRoles)) {
            return $next($request);
        }

        abort(403);
    }
}

