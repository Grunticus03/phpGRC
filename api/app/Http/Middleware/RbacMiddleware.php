<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC middleware with route-level role enforcement.
 * Declare roles on routes with ->defaults('roles', ['Admin', 'Auditor'])
 */
final class RbacMiddleware
{
    /**
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
