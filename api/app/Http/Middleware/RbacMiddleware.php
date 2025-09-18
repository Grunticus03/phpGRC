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

        /** @var \Illuminate\Routing\Route $route */
        $route = $request->route();

        /** @var array<string,mixed> $defaults */
        $defaults = $route->defaults;

        // Capability flag blocks regardless of auth/rbac mode.
        /** @var mixed $capAny */
        $capAny = $defaults['capability'] ?? null;
        $capKey = is_string($capAny) ? $capAny : null;
        if ($capKey !== null && $capKey !== '') {
            $capEnabled = (bool) config('core.capabilities.' . $capKey, true);
            if (!$capEnabled) {
                return response()->json([
                    'ok'         => false,
                    'code'       => 'CAPABILITY_DISABLED',
                    'capability' => $capKey,
                ], 403);
            }
        }

        /** @var mixed $rolesAny */
        $rolesAny = $defaults['roles'] ?? [];
        /** @var list<string> $requiredRoles */
        $requiredRoles = [];
        if (is_string($rolesAny)) {
            $requiredRoles = [$rolesAny];
        } elseif (is_array($rolesAny)) {
            /** @var mixed $r */
            foreach ($rolesAny as $r) {
                if (is_string($r)) {
                    $requiredRoles[] = $r;
                }
            }
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

        if ($requiredRoles !== []) {
            if (!$user->hasAnyRole($requiredRoles)) {
                return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
            }
        }

        /** @var mixed $policyAny */
        $policyAny = $defaults['policy'] ?? null;
        $policy = is_string($policyAny) ? $policyAny : null;
        if ($policy !== null && $policy !== '') {
            if (!RbacEvaluator::allows($user, $policy)) {
                return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
            }
        }

        /** @var Response $resp */
        $resp = $next($request);
        return $resp;
    }
}

