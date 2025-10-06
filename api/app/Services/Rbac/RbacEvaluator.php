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
        if (! $this->isEnabled()) {
            return true; // RBAC disabled => skip
        }

        /** @var mixed $modeVal */
        $modeVal = config('core.rbac.mode');
        $mode = is_string($modeVal) ? $modeVal : 'stub';

        // Treat "1", 1, "true", "on", "yes" as true
        $persist = ($mode === 'persist') || self::boolish(config('core.rbac.persistence'));

        if (! $persist) {
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
     * @param  array<int,string>  $roles
     */
    public function userHasAnyRole(?User $user, array $roles): bool
    {
        if ($user === null) {
            return false;
        }

        /** @var Collection<int,string> $namesCol */
        $namesCol = $user->roles()->pluck('name');

        /** @var array<string, true> $userTokens */
        $userTokens = [];
        foreach ($namesCol as $n) {
            $tok = self::normalizeToken($n);
            if ($tok !== '') {
                $userTokens[$tok] = true;
            }
        }
        if ($userTokens === []) {
            return false;
        }

        foreach ($roles as $r) {
            $tok = self::normalizeToken($r);
            if ($tok !== '' && isset($userTokens[$tok])) {
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
        $capVal = config('core.capabilities.'.$capKey);
        if (! is_bool($capVal) || $capVal === false) {
            return false;
        }

        // Admin wildcard by default.
        $map = PolicyMap::roleCapabilities();

        /** @var Collection<int,string> $roles */
        $roles = $user->roles()->pluck('name');

        foreach ($roles as $roleName) {
            $caps = $map[self::normalizeToken($roleName)] ?? [];
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

    /**
     * Normalize role tokens to match PolicyMap:
     * - trim
     * - collapse internal whitespace to single space
     * - replace spaces with underscore
     * - allow ^[\p{L}\p{N}_-]{2,64}$, then lowercase
     */
    private static function normalizeToken(string $name): string
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
