<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Authorization\RbacEvaluator;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // Collect role and policy requirements from route
        $declaredRoles = $route->defaults['roles'] ?? [];
        $requiredRoles = is_string($declaredRoles) ? [$declaredRoles] : (array) $declaredRoles;

        $declaredPolicy  = $route->defaults['policy']  ?? null;
        $declaredPolicies = $route->defaults['policies'] ?? [];
        $requiredPolicies = array_values(array_filter(array_unique(array_merge(
            is_string($declaredPolicy) ? [$declaredPolicy] : [],
            (array) $declaredPolicies
        )), static fn ($v) => is_string($v) && $v !== ''));

        // If nothing is required, continue
        if ($requiredRoles === [] && $requiredPolicies === []) {
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
            // Anonymous passthrough when auth not required and nothing to enforce strictly
            // In persist mode, policies/roles still deny without user; evaluator handles that.
        }

        // Enforce roles if declared
        if ($requiredRoles !== []) {
            if ($user instanceof User) {
                if (!$user->hasAnyRole($requiredRoles)) {
                    return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
                }
            } else {
                // No user present
                if (RbacEvaluator::persistenceEnabled()) {
                    // Strict when persisting
                    return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
                }
                // Stub mode: allow
            }
        }

        // Enforce policies if declared (require ANY policy to pass)
        if ($requiredPolicies !== []) {
            $anyAllowed = false;
            foreach ($requiredPolicies as $policy) {
                if (RbacEvaluator::allows($user instanceof User ? $user : null, $policy)) {
                    $anyAllowed = true;
                    break;
                }
            }

            if (!$anyAllowed) {
                return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
            }
        }

        return $next($request);
    }
}

