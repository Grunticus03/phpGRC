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
     * @param array<int,string> $roles
     */
    public function userHasAnyRole(?User $user, array $roles): bool
    {
        if (!$user) {
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
        if (!$user) {
            return false;
        }

        // Global feature switch first. If disabled, deny.
        if (!(bool) config('core.capabilities.' . $capKey, true)) {
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
}
