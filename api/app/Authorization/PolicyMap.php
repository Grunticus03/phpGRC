<?php
declare(strict_types=1);

namespace App\Authorization;

/**
 * Central mapping of policy keys to the roles that satisfy them.
 * Keys are stable strings used in gates and route defaults.
 *
 * Defaults are safe and minimal. You can extend or override by adding
 * config('core.rbac.policies') entries with the same shape.
 */
final class PolicyMap
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function map(): array
    {
        // Built-in defaults
        $defaults = [
            // Core settings
            'core.settings.manage' => ['Admin'],

            // Audit and evidence viewing
            'core.audit.view'      => ['Admin', 'Auditor'],
            'core.evidence.view'   => ['Admin', 'Auditor'],

            // Evidence write
            'core.evidence.manage' => ['Admin'],

            // Exports
            'core.exports.generate' => ['Admin'],

            // RBAC admin
            'rbac.roles.manage'      => ['Admin'],
            'rbac.user_roles.manage' => ['Admin'],
        ];

        /** @var array<string, array<int, string>>|null $overrides */
        $overrides = config('core.rbac.policies');

        if (is_array($overrides)) {
            // Shallow merge: override keys or add new
            return array_replace($defaults, $overrides);
        }

        return $defaults;
    }

    /**
     * @param string $policy
     * @return array<int, string>
     */
    public static function rolesFor(string $policy): array
    {
        $map = self::map();
        return $map[$policy] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public static function allKeys(): array
    {
        return array_keys(self::map());
    }
}

