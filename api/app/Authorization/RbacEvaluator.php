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
        /** @var mixed $raw */
        $raw = config('core.rbac.enabled');
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $v = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            return $v ?? false;
        }
        if (is_int($raw)) {
            return $raw !== 0;
        }
        return false;
    }

    public static function requireAuth(): bool
    {
        /** @var mixed $raw */
        $raw = config('core.rbac.require_auth');
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $v = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            return $v ?? false;
        }
        if (is_int($raw)) {
            return $raw !== 0;
        }
        return false;
    }

    public static function persistenceEnabled(): bool
    {
        /** @var mixed $modeRaw */
        $modeRaw = config('core.rbac.mode');
        $mode = is_string($modeRaw) && $modeRaw !== '' ? $modeRaw : 'stub';

        /** @var mixed $persistenceRaw */
        $persistenceRaw = config('core.rbac.persistence');
        $persistence = match (true) {
            is_bool($persistenceRaw)   => $persistenceRaw,
            is_string($persistenceRaw) => (filter_var($persistenceRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false),
            is_int($persistenceRaw)    => $persistenceRaw !== 0,
            default                    => false,
        };

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
     * @param array<int,string> $roles
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
