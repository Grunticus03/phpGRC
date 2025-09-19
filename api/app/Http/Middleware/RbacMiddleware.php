<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Rbac\RbacEvaluator;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RbacMiddleware
{
    /**
     * @param \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) config('core.rbac.enabled', false);
        $request->attributes->set('rbac_enabled', $enabled);

        if (!$enabled) {
            /** @var Response $resp */
            $resp = $next($request);
            return $resp;
        }

        $route = $request->route();
        if (!$route instanceof \Illuminate\Routing\Route) {
            /** @var Response $resp */
            $resp = $next($request);
            return $resp;
        }

        /** @var array<string,mixed> $defaults */
        $defaults = $route->defaults;

        // Capability flag blocks regardless of auth/rbac mode.
        $capKey = (isset($defaults['capability']) && is_string($defaults['capability']))
            ? $defaults['capability']
            : null;

        if ($capKey !== null && $capKey !== '') {
            $capEnabled = config('core.capabilities.' . $capKey);
            if (!is_bool($capEnabled) || $capEnabled === false) {
                return response()->json([
                    'ok'         => false,
                    'code'       => 'CAPABILITY_DISABLED',
                    'capability' => $capKey,
                ], 403);
            }
        }

        $requiredRoles = [];
        if (isset($defaults['roles']) && is_string($defaults['roles'])) {
            $requiredRoles = [$defaults['roles']];
        } elseif (isset($defaults['roles']) && is_array($defaults['roles'])) {
            /** @var list<string> $requiredRoles */
            $requiredRoles = array_values(
                array_map('strval', array_filter($defaults['roles'], 'is_string'))
            );
        }

        $requireAuth = (bool) config('core.rbac.require_auth', false);

        Auth::shouldUse('sanctum');
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            if ($requireAuth) {
                throw new AuthenticationException();
            }
            /** @var Response $resp */
            $resp = $next($request);
            return $resp;
        }

        if ($requiredRoles !== [] && !$user->hasAnyRole($requiredRoles)) {
            return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
        }

        $policy = (isset($defaults['policy']) && is_string($defaults['policy']))
            ? $defaults['policy']
            : null;

        if ($policy !== null && $policy !== '' && !RbacEvaluator::allows($user, $policy)) {
            return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
        }

        /** @var Response $resp */
        $resp = $next($request);
        return $resp;
    }
}

