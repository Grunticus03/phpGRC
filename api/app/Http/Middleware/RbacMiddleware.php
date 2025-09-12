<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;

final class RbacMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) config('core.rbac.enabled', false);
        $request->attributes->set('rbac_enabled', $enabled);

        $route = $request->route();
        if (!$enabled || $route === null) {
            return $next($request);
        }

        // Optional capability flag on the route. Blocks even before auth/roles.
        $capKey = $route->defaults['capability'] ?? null;
        if (is_string($capKey) && $capKey !== '') {
            $capEnabled = (bool) config('core.capabilities.' . $capKey, true);
            if (!$capEnabled) {
                return response()->json([
                    'ok'         => false,
                    'code'       => 'CAPABILITY_DISABLED',
                    'capability' => $capKey,
                ], 403);
            }
        }

        $declared = $route->defaults['roles'] ?? [];
        $requiredRoles = is_string($declared) ? [$declared] : (array) $declared;
        if ($requiredRoles === []) {
            return $next($request);
        }

        // Use Sanctum to resolve the user from Bearer PATs
        Auth::shouldUse('sanctum');
        $user = Auth::user();

        $requireAuth = (bool) config('core.rbac.require_auth', false);

        if (!$user) {
            if ($requireAuth) {
                throw new AuthenticationException(); // -> 401 by handler
            }
            // Anonymous passthrough when auth not required
            return $next($request);
        }

        if ($user instanceof User && $user->hasAnyRole($requiredRoles)) {
            return $next($request);
        }

        return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
    }
}

