<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Schema;

final class PolicyMap
{
    /**
     * Memoize computed map within the request to avoid repeated normalization.
     * Cache is invalidated when the config/mode/roles fingerprint changes.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $cache = null;

    /** @var string|null */
    private static ?string $cacheKey = null;

    /**
     * Track which policies we already audited for unknown roles to avoid spam.
     * @var array<string, true>
     */
    private static array $auditedUnknownRoles = [];

    /**
     * Default PolicyMap before overrides. Sanitized to list<string> values.
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        /** @var mixed $raw */
        $raw = config('core.rbac.policies');
        if (!is_array($raw)) {
            return [];
        }
        /** @var array<array-key, mixed> $raw */

        /** @var array<string, list<string>> $sanitized */
        $sanitized = [];

        /** @var array<int, array-key> $keys */
        $keys = array_keys($raw);
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            /** @var mixed $valRaw */
            $valRaw = $raw[$key];

            /** @var list<string> $list */
            $list = [];
            if (is_array($valRaw)) {
                /** @var array<int, mixed> $valRaw */
                $list = array_values(array_filter(
                    $valRaw,
                    static fn ($x): bool => is_string($x) && $x !== ''
                ));
            }

            $sanitized[$key] = $list;
        }

        return $sanitized;
    }

    /**
     * Effective PolicyMap after applying normalization against the role catalog.
     * @return array<string, list<string>>
     */
    public static function effective(): array
    {
        $fingerprint = self::fingerprint();
        if (self::$cache !== null && self::$cacheKey === $fingerprint) {
            return self::$cache;
        }

        $base = self::defaults();

        // Normalize every list against the current role catalog
        $catalog = self::roleCatalog(); // canonical display names
        $canonByLower = [];
        foreach ($catalog as $name) {
            $canonByLower[mb_strtolower($name)] = $name;
        }

        /** @var mixed $modeVal */
        $modeVal = config('core.rbac.mode');
        $mode = is_string($modeVal) ? $modeVal : 'stub';

        // Treat "1", 1, "true", "on", "yes" as true
        $persist = ($mode === 'persist') || self::boolish(config('core.rbac.persistence'));

        $normalized = [];
        foreach ($base as $policy => $roles) {
            // $roles already list<string>
            $unknown = [];
            $norm = [];

            foreach ($roles as $raw) {
                $key = self::normalizeToken($raw);
                if ($key === '') {
                    continue;
                }
                $canon = $canonByLower[$key] ?? null;
                if ($canon === null) {
                    $unknown[] = $raw;
                    continue;
                }
                $norm[$canon] = true; // dedupe
            }

            /** @var list<string> $effectiveList */
            $effectiveList = array_keys($norm);

            if ($persist && $unknown !== [] && !isset(self::$auditedUnknownRoles[$policy])) {
                self::$auditedUnknownRoles[$policy] = true;
                self::auditUnknownRoles($policy, $unknown);
            }

            // In persist, empty list => deny; in stub, evaluator allows. Keep [] here.
            $normalized[$policy] = $effectiveList;
        }

        self::$cache = $normalized;
        self::$cacheKey = $fingerprint;
        // Reset per-fingerprint to prevent stale suppression
        self::$auditedUnknownRoles = [];

        return $normalized;
    }

    /**
     * Return allowed role names for a policy, or null if key unknown.
     * @return list<string>|null
     */
    public static function rolesForPolicy(string $policy): ?array
    {
        $map = self::effective();
        if (!array_key_exists($policy, $map)) {
            return null;
        }
        /** @var list<string> $list */
        $list = $map[$policy];
        return $list;
    }

    /**
     * Role catalog source.
     * - persist path: DB-backed Role names if available
     * - stub path: config('core.rbac.roles')
     * @return list<string>
     */
    public static function roleCatalog(): array
    {
        /** @var mixed $modeVal */
        $modeVal = config('core.rbac.mode');
        $mode = is_string($modeVal) ? $modeVal : 'stub';

        // Treat "1", 1, "true", "on", "yes" as true
        $persist = ($mode === 'persist') || self::boolish(config('core.rbac.persistence'));

        if ($persist && class_exists(\App\Models\Role::class) && Schema::hasTable('roles')) {
            try {
                /** @var array<int, mixed> $names */
                $names = \App\Models\Role::query()->orderBy('name')->pluck('name')->all();
                if ($names !== []) {
                    /** @var list<string> $out */
                    $out = array_values(array_filter(
                        $names,
                        static fn ($n): bool => is_string($n) && $n !== ''
                    ));
                    if ($out !== []) {
                        return $out;
                    }
                }
            } catch (\Throwable) {
                // fall through to config
            }
        }

        /** @var mixed $cfg */
        $cfg = config('core.rbac.roles');
        /** @var list<string> $out */
        $out = [];
        if (is_array($cfg)) {
            /** @var array<int, mixed> $cfg */
            $out = array_values(array_filter(
                $cfg,
                static fn ($n): bool => is_string($n) && $n !== ''
            ));
        }
        return $out;
    }

    /**
     * Lightweight unknown-role audit. Best-effort only.
     * Emits one record per policy per process to reduce noise.
     * Category: RBAC, action: rbac.policy.override.unknown_role
     *
     * @param list<string> $unknown
     */
    private static function auditUnknownRoles(string $policy, array $unknown): void
    {
        try {
            if (!config('core.audit.enabled', true) || !Schema::hasTable('audit_events')) {
                return;
            }

            if ($policy === '') {
                return;
            }
            /** @var non-empty-string $policyId */
            $policyId = $policy;

            /** @var AuditLogger $audit */
            $audit = app(AuditLogger::class);
            $audit->log([
                'actor_id'    => null,
                'action'      => 'rbac.policy.override.unknown_role',
                'category'    => 'RBAC',
                'entity_type' => 'rbac.policy',
                'entity_id'   => $policyId,
                'ip'          => null,
                'ua'          => null,
                'meta'        => ['unknown_roles' => $unknown],
            ]);
        } catch (\Throwable) {
            // swallow; auditing must not break request path
        }
    }

    /**
     * Normalize a role token: trim, collapse internal spaces, lowercase key.
     */
    private static function normalizeToken(string $name): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $name);
        $name = trim(is_string($collapsed) ? $collapsed : $name);
        if ($name === '') {
            return '';
        }
        return mb_strtolower($name);
    }

    /**
     * Role→capabilities map for UI gating. '*' = wildcard.
     * @return array<string, list<string>>
     */
    public static function roleCapabilities(): array
    {
        return [
            'Admin'   => ['*'],
            'Auditor' => [],
            'User'    => [],
        ];
    }

    /**
     * Wildcard helper.
     * @param list<string> $caps
     */
    public static function hasWildcard(array $caps): bool
    {
        return in_array('*', $caps, true);
    }

    /**
     * Clear internal cache (useful for tests).
     */
    public static function clearCache(): void
    {
        self::$cache = null;
        self::$cacheKey = null;
        self::$auditedUnknownRoles = [];
    }

    /**
     * Compute a fingerprint for cache invalidation across config changes.
     */
    private static function fingerprint(): string
    {
        /** @var mixed $pol */
        $pol = config('core.rbac.policies');
        /** @var mixed $mode */
        $mode = config('core.rbac.mode');
        /** @var mixed $persist */
        $persist = config('core.rbac.persistence');
        $catalog = self::roleCatalog();

        $payload = json_encode([$pol, $mode, $persist, $catalog], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = (string) microtime(true);
        }

        return hash('sha1', $payload);
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

