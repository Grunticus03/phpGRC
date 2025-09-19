<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Models\User;
use App\Support\Rbac\PolicyMap;
use Illuminate\Support\Collection;

final class RbacEvaluator
{
    public function isEnabled(): bool
    {
        return (bool) config('core.rbac.enabled', false);
    }

    /**
     * Static convenience for middleware.
     */
    public static function allows(?User $user, string $policy): bool
    {
        /** @var self $svc */
        $svc = app(self::class);
        return $svc->allowsUserPolicy($user, $policy);
    }

    /**
     * Policy evaluation with stub/persist semantics.
     */
    public function allowsUserPolicy(?User $user, string $policy): bool
    {
        if (!$this->isEnabled()) {
            return true; // RBAC disabled => skip
        }

        /** @var mixed $modeVal */
        $modeVal = config('core.rbac.mode');
        $mode = is_string($modeVal) ? $modeVal : 'stub';

        // Treat "1", 1, "true", "on", "yes" as true
        $persist = ($mode === 'persist') || self::boolish(config('core.rbac.persistence'));

        if (!$persist) {
            return true; // stub mode allows
        }

        $allowedRoles = PolicyMap::rolesForPolicy($policy);
        if ($allowedRoles === null) {
            // Unknown policy key in persist => deny
            return false;
        }

        return $this->userHasAnyRole($user, $allowedRoles);
    }

    /**
     * @param array<int,string> $roles
     */
    public function userHasAnyRole(?User $user, array $roles): bool
    {
        if ($user === null) {
            return false;
        }

        // If User model exposes hasAnyRole(), use it. Else fall back to names lookup.
        if (method_exists($user, 'hasAnyRole')) {
            /** @var callable $fn */
            $fn = [$user, 'hasAnyRole'];
            /** @var bool $ok */
            $ok = $fn($roles);
            return $ok;
        }

        /** @var Collection<int,string> $names */
        $names = $user->roles()->pluck('name');
        foreach ($roles as $r) {
            if ($names->containsStrict($r)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Capability allowed if:
     *  - Global capability flag is enabled in config, AND
     *  - User holds a role that grants the capability (or wildcard '*').
     */
    public function userHasCapability(?User $user, string $capKey): bool
    {
        if ($user === null) {
            return false;
        }

        // Global feature switch first. If disabled, deny.
        /** @var mixed $capVal */
        $capVal = config('core.capabilities.' . $capKey);
        if (!is_bool($capVal) || $capVal === false) {
            return false;
        }

        // Admin wildcard by default.
        $map = PolicyMap::roleCapabilities();

        /** @var Collection<int,string> $roles */
        $roles = $user->roles()->pluck('name');

        foreach ($roles as $roleName) {
            $caps = $map[$roleName] ?? [];
            if (PolicyMap::hasWildcard($caps)) {
                return true;
            }
            foreach ($caps as $c) {
                if ($c === $capKey) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Coerce mixed to boolean with common truthy strings/numbers.
     */
    private static function boolish(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        if (is_string($v)) {
            $t = strtolower(trim($v));
            return in_array($t, ['1', 'true', 'on', 'yes'], true);
        }
        return false;
    }
}

