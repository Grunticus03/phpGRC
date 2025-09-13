<?php
declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;

/**
 * Evaluates RBAC decisions for roles and fine-grained policies.
 * Enforcement is strict only when persistence is enabled:
 *   core.rbac.mode = 'persist' OR core.rbac.persistence = true
 * In stub mode, all policy checks allow.
 */
final class RbacEvaluator
{
    public static function enabled(): bool
    {
        return (bool) config('core.rbac.enabled', false);
    }

    public static function requireAuth(): bool
    {
        return (bool) config('core.rbac.require_auth', false);
    }

    public static function persistenceEnabled(): bool
    {
        $mode        = (string) config('core.rbac.mode', 'stub');
        $persistence = (bool) (config('core.rbac.persistence', false) ?? false);
        return $mode === 'persist' || $persistence === true;
    }

    /**
     * Core policy decision.
     * - If RBAC disabled: allow.
     * - If stub mode: allow.
     * - If persist mode and no user: deny (unless caller chose to bypass).
     * - If policy unknown in persist mode: deny by default.
     * - Else require user to have at least one allowed role.
     */
    public static function allows(?User $user, string $policy): bool
    {
        if (!self::enabled()) {
            return true;
        }

        if (!self::persistenceEnabled()) {
            // Stub mode: permissive
            return true;
        }

        if ($user === null) {
            // In persist mode, no subject -> deny
            return false;
        }

        $roles = PolicyMap::rolesFor($policy);
        if ($roles === []) {
            // Unknown policy in persist mode => deny by default
            return false;
        }

        return $user->hasAnyRole($roles);
    }

    /**
     * Convenience for raw role checks in persist mode.
     */
    public static function userHasAnyRole(?User $user, array $roles): bool
    {
        if (!self::enabled()) {
            return true;
        }

        if (!self::persistenceEnabled()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole($roles);
    }
}

