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

        // Capability flag blocks regardless of auth/rbac mode.
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

        $declaredRoles = $route->defaults['roles'] ?? [];
        $requiredRoles = is_string($declaredRoles) ? [$declaredRoles] : (array) $declaredRoles;

        $requireAuth = (bool) config('core.rbac.require_auth', false);

        // Sanctum resolves user from PATs if present.
        Auth::shouldUse('sanctum');
        $user = Auth::user();

        // If auth is required, enforce presence. Else, anonymous passthrough.
        if (!$user) {
            if ($requireAuth) {
                throw new AuthenticationException(); // 401 via handler
            }
            // In stub or persist modes, when auth is not required, skip role/policy checks.
            return $next($request);
        }

        // Enforce roles only when a user is present.
        if ($requiredRoles !== []) {
            if ($user instanceof User && !$user->hasAnyRole($requiredRoles)) {
                return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
            }
        }

        // Enforce fine-grained policy when declared.
        $policy = $route->defaults['policy'] ?? null;
        if (is_string($policy) && $policy !== '') {
            $subject = $user instanceof User ? $user : null;
            if (!RbacEvaluator::allows($subject, $policy)) {
                return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
            }
        }

        return $next($request);
    }
}

