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

        if (!$enabled) {
            return $next($request);
        }

        /** @var \Illuminate\Routing\Route $route */
        $route = $request->route();

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

        Auth::shouldUse('sanctum');
        /** @var User|null $user */
        $user = Auth::user();

        if (!$user) {
            if ($requireAuth) {
                throw new AuthenticationException();
            }
            return $next($request);
        }

        if ($requiredRoles !== []) {
            if (!$user->hasAnyRole($requiredRoles)) {
                return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
            }
        }

        $policy = $route->defaults['policy'] ?? null;
        if (is_string($policy) && $policy !== '') {
            if (!RbacEvaluator::allows($user, $policy)) {
                return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
            }
        }

        return $next($request);
    }
}
