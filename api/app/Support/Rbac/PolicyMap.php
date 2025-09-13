<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * Default role→capability mapping.
 * Safe baseline: Admin gets all ("*"). Others empty unless you opt-in.
 *
 * Capabilities are free-form strings. Prefer dotted keys like:
 *   core.exports.generate
 *   core.audit.read
 *   core.evidence.read
 */
final class PolicyMap
{
    public const ROLE_ADMIN        = 'Admin';
    public const ROLE_AUDITOR      = 'Auditor';
    public const ROLE_RISK_MANAGER = 'Risk Manager';
    public const ROLE_USER         = 'User';

    /**
     * @return array<string, array<int, string>>
     */
    public static function roleCapabilities(): array
    {
        // Merge user-configured overrides if present.
        /** @var array<string, array<int, string>> $overrides */
        $overrides = (array) config('core.policy.role_caps', []);

        $defaults = [
            self::ROLE_ADMIN        => ['*'], // wildcard: all capabilities allowed
            self::ROLE_AUDITOR      => [
                // examples; not enforced unless you use RbacEvaluator::userHasCapability
                'core.audit.read',
                'core.exports.status',
                'core.exports.download',
            ],
            self::ROLE_RISK_MANAGER => [],
            self::ROLE_USER         => [],
        ];

        // Shallow merge. Override completely per role if provided.
        return $overrides + $defaults;
    }

    /**
     * @param array<int,string> $caps
     */
    public static function hasWildcard(array $caps): bool
    {
        foreach ($caps as $c) {
            if ($c === '*') {
                return true;
            }
        }
        return false;
    }
}
