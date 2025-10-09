<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\RbacEvaluator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class RbacMiddleware
{
    private const ATTR_DENY_AUDITED = 'rbac_deny_audit_emitted';

    public function __construct(
        private readonly RbacEvaluator $evaluator,
        private readonly AuditLogger $audit
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) config('core.rbac.enabled', false);
        $request->attributes->set('rbac_enabled', $enabled);

        if (! $enabled) {
            /** @var Response $resp */
            $resp = $next($request);

            return $resp;
        }

        $route = $request->route();
        if (! $route instanceof \Illuminate\Routing\Route) {
            /** @var Response $resp */
            $resp = $next($request);

            return $resp;
        }

        /** @var array<string,mixed> $defaults */
        $defaults = $route->defaults;

        // Capability gate
        $capKey = (isset($defaults['capability']) && is_string($defaults['capability']) && $defaults['capability'] !== '')
            ? $defaults['capability']
            : null;

        if ($capKey !== null) {
            $capEnabled = config('core.capabilities.'.$capKey);
            if (! is_bool($capEnabled) || $capEnabled === false) {
                $this->auditDeny($request, null, 'rbac.deny.capability', [
                    'capability' => $capKey,
                    'reason' => 'capability',
                    'rbac_mode' => $this->rbacMode(),
                    'required_roles' => $this->extractRequiredRoles($defaults),
                    'policy' => $this->extractPolicy($defaults),
                ]);

                return new JsonResponse(['ok' => false, 'code' => 'CAPABILITY_DISABLED', 'capability' => $capKey], 403);
            }
        }

        /** @var list<string> $requiredRoles */
        $requiredRoles = $this->extractRequiredRoles($defaults);
        $policy = $this->extractPolicy($defaults);
        $requireAuth = (bool) config('core.rbac.require_auth', false);

        Auth::shouldUse('sanctum');
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            if ($requireAuth) {
                if (! $this->shouldSkipRedirectAudit($request)) {
                    $this->auditRedirect($request, $capKey, $requiredRoles, $policy);
                }
                $this->auditDeny($request, null, 'rbac.deny.unauthenticated', [
                    'reason' => 'unauthenticated',
                    'rbac_mode' => $this->rbacMode(),
                    'required_roles' => $requiredRoles,
                    'policy' => $policy,
                    'capability' => $capKey,
                ]);

                return response()->json(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401);
            }
            /** @var Response $resp */
            $resp = $next($request);

            return $resp;
        }

        if ($requiredRoles !== [] && ! $this->evaluator->userHasAnyRole($user, $requiredRoles)) {
            $this->auditDeny($request, $user, 'rbac.deny.role_mismatch', [
                'reason' => 'role',
                'rbac_mode' => $this->rbacMode(),
                'required_roles' => $requiredRoles,
                'policy' => $policy,
                'capability' => $capKey,
            ]);

            return new JsonResponse(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
        }

        if ($policy !== null && $policy !== '') {
            $policyAllowed = RbacEvaluator::allows($user, $policy);
            $request->attributes->set('rbac_policy_allowed', $policyAllowed);
            if (! $policyAllowed) {
                $this->auditDeny($request, $user, 'rbac.deny.policy', [
                    'reason' => 'policy',
                    'rbac_mode' => $this->rbacMode(),
                    'required_roles' => $requiredRoles,
                    'policy' => $policy,
                    'capability' => $capKey,
                ]);

                return new JsonResponse(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Forbidden'], 403);
            }
        }

        /** @var Response $resp */
        $resp = $next($request);

        return $resp;
    }

    private function shouldSkipRedirectAudit(Request $request): bool
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $requiredRoles
     */
    private function auditRedirect(Request $request, ?string $capability, array $requiredRoles, ?string $policy): void
    {
        try {
            /** @var \Illuminate\Routing\Route|null $routeObj */
            $routeObj = $request->route();
            $routeName = null;
            $routeAction = null;

            if ($routeObj !== null) {
                $rn = $routeObj->getName();
                if ($rn !== null && $rn !== '') {
                    $routeName = $rn;
                }
                $ra = $routeObj->getActionName();
                if ($ra !== '') {
                    $routeAction = $ra;
                }
            }

            $method = $request->getMethod();
            $path = '/'.ltrim($request->path(), '/');

            $meta = array_filter([
                'reason' => 'require_auth',
                'capability' => $capability,
                'policy' => $policy,
                'required_roles' => $requiredRoles !== [] ? $requiredRoles : null,
                'rbac_mode' => $this->rbacMode(),
                'route_name' => $routeName,
                'route_action' => $routeAction,
                'route' => $path,
                'method' => $method,
                'request_id' => (string) Str::ulid(),
            ], static fn ($v) => $v !== null);

            $this->audit->log([
                'actor_id' => null,
                'action' => 'auth.login.redirected',
                'category' => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id' => 'login_redirect',
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'meta' => $meta,
            ]);
        } catch (\Throwable) {
            // Intentionally swallow audit errors for redirects.
        }
    }

    /**
     * @param  array<string,mixed>  $defaults
     * @return list<string>
     */
    private function extractRequiredRoles(array $defaults): array
    {
        if (isset($defaults['roles']) && is_string($defaults['roles'])) {
            return [$defaults['roles']];
        }
        if (isset($defaults['roles']) && is_array($defaults['roles'])) {
            /** @var list<string> $roles */
            $roles = array_values(array_map('strval', array_filter($defaults['roles'], 'is_string')));

            return $roles;
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $defaults
     */
    private function extractPolicy(array $defaults): ?string
    {
        return (isset($defaults['policy']) && is_string($defaults['policy']) && $defaults['policy'] !== '')
            ? $defaults['policy']
            : null;
    }

    /**
     * Runtime-safe RBAC mode string.
     */
    private function rbacMode(): string
    {
        /** @var mixed $raw */
        $raw = config('core.rbac.mode');

        return is_string($raw) && $raw !== '' ? $raw : 'stub';
    }

    /**
     * Write exactly one RBAC deny audit for this request.
     *
     * @param  non-empty-string  $action
     * @param  array<string,mixed>  $extraMeta
     */
    private function auditDeny(Request $request, ?User $user, string $action, array $extraMeta = []): void
    {
        try {
            if ($request->attributes->get(self::ATTR_DENY_AUDITED) === true) {
                return;
            }

            /** @var \Illuminate\Routing\Route|null $routeObj */
            $routeObj = $request->route();
            $routeName = null;
            $routeAction = null;

            if ($routeObj !== null) {
                $rn = $routeObj->getName();
                if ($rn !== null && $rn !== '') {
                    $routeName = $rn;
                }
                $ra = $routeObj->getActionName();
                if ($ra !== '') {
                    $routeAction = $ra;
                }
            }

            $method = $request->getMethod();
            $path = '/'.ltrim($request->path(), '/');
            $entityId = $method.' '.$path;

            /** @var list<string>|null $rolesUser */
            $rolesUser = null;
            if ($user !== null) {
                /** @var array<int,mixed> $names */
                $names = $user->roles()->pluck('name')->all();
                /** @var list<string> $namesStr */
                $namesStr = array_values(array_filter($names, 'is_string'));
                $rolesUser = [];
                foreach ($namesStr as $n) {
                    $tok = $this->normalizeToken($n);
                    if ($tok !== '') {
                        $rolesUser[] = $tok;
                    }
                }
            }

            $meta = array_filter([
                'reason' => $extraMeta['reason'] ?? null,
                'policy' => $extraMeta['policy'] ?? null,
                'capability' => $extraMeta['capability'] ?? null,
                'required_roles' => $extraMeta['required_roles'] ?? null,
                'roles_user' => $rolesUser,
                'rbac_mode' => $extraMeta['rbac_mode'] ?? null,
                'route_name' => $routeName,
                'route_action' => $routeAction,
                'route' => $path,
                'method' => $method,
                'request_id' => (string) Str::ulid(),
            ], static fn ($v) => $v !== null);

            $this->audit->log([
                'actor_id' => $user?->id,
                'action' => $action,
                'category' => 'RBAC',
                'entity_type' => 'route',
                'entity_id' => $entityId,
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'meta' => $meta,
            ]);

            $request->attributes->set(self::ATTR_DENY_AUDITED, true);
        } catch (\Throwable) {
            // Intentionally swallow audit errors.
        }
    }

    /**
     * Normalize role token.
     */
    private function normalizeToken(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $collapsed = preg_replace('/\s+/', ' ', $name);
        $name = is_string($collapsed) ? $collapsed : $name;
        $name = str_replace(' ', '_', $name);
        if (! preg_match('/^[\p{L}\p{N}_-]{2,64}$/u', $name)) {
            return '';
        }

        return mb_strtolower($name);
    }
}
