<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PolicyMap
{
    /**
     * @var array<string, list<string>>|null
     */
    private static ?array $cache = null;

    private static ?string $cacheKey = null;

    /**
     * Tracks which policies have already emitted the unknown-role audit
     * during this PHP process lifetime (i.e., this "boot").
     *
     * @var array<string, true>
     */
    private static array $auditedUnknownRoles = [];

    /**
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        /** @var mixed $raw */
        $raw = config('core.rbac.policies');
        if (! is_array($raw)) {
            return [];
        }

        /** @var array<string, list<string>> $sanitized */
        $sanitized = [];

        /** @var array<int, array-key> $keys */
        $keys = array_keys($raw);
        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            /** @var mixed $valRaw */
            $valRaw = $raw[$key];

            /** @var list<string> $list */
            $list = [];
            if (is_array($valRaw)) {
                /** @var list<string> $valStrs */
                $valStrs = array_values(array_filter($valRaw, 'is_string'));
                foreach ($valStrs as $v) {
                    $tok = self::normalizeToken($v);
                    if ($tok !== '') {
                        $list[] = $tok;
                    }
                }
            }

            $sanitized[$key] = $list;
        }

        return $sanitized;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function effective(): array
    {
        $fingerprint = self::fingerprint();
        if (self::$cache !== null && self::$cacheKey === $fingerprint) {
            return self::$cache;
        }

        /** @var mixed $modeVal */
        $modeVal = config('core.rbac.mode');
        $mode = is_string($modeVal) ? $modeVal : 'stub';
        $persist = ($mode === 'persist') || self::boolish(config('core.rbac.persistence'));

        $base = self::defaults();
        $dbAssignments = $persist ? self::databaseAssignments() : null;

        if ($persist && $dbAssignments !== null) {
            $base = array_replace($base, $dbAssignments);
        }

        $catalog = self::roleCatalog();
        $canon = [];
        foreach ($catalog as $name) {
            $canon[$name] = true;
        }

        $normalized = [];
        foreach ($base as $policy => $roles) {
            $unknown = [];
            $norm = [];

            foreach ($roles as $raw) {
                if (! isset($canon[$raw])) {
                    $unknown[] = $raw;

                    continue;
                }
                $norm[$raw] = true;
            }

            /** @var list<string> $effectiveList */
            $effectiveList = array_keys($norm);

            if ($persist && $unknown !== [] && ! isset(self::$auditedUnknownRoles[$policy])) {
                self::$auditedUnknownRoles[$policy] = true;
                self::auditUnknownRoles($policy, $unknown);
            }

            $normalized[$policy] = $effectiveList;
        }

        self::$cache = $normalized;
        self::$cacheKey = $fingerprint;

        return $normalized;
    }

    /**
     * @return list<string>|null
     */
    public static function rolesForPolicy(string $policy): ?array
    {
        $map = self::effective();
        if (! array_key_exists($policy, $map)) {
            return null;
        }
        /** @var list<string> $list */
        $list = $map[$policy];

        return $list;
    }

    /**
     * @return list<string>
     */
    public static function policyKeys(): array
    {
        $map = self::effective();
        /** @var list<string> $keys */
        $keys = array_keys($map);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function roleCatalog(): array
    {
        /** @var mixed $modeVal */
        $modeVal = config('core.rbac.mode');
        $mode = is_string($modeVal) ? $modeVal : 'stub';

        $persist = ($mode === 'persist') || self::boolish(config('core.rbac.persistence'));

        if ($persist && class_exists(\App\Models\Role::class) && self::hasTable('roles')) {
            try {
                /** @var \Illuminate\Support\Collection<int,\App\Models\Role> $rows */
                $rows = \App\Models\Role::query()
                    ->orderBy('name')
                    ->get(['id', 'name']);

                if ($rows->isNotEmpty()) {
                    $tokens = [];
                    foreach ($rows as $role) {
                        /** @var mixed $rawName */
                        $rawName = $role->getAttribute('name');
                        if (is_string($rawName) && $rawName !== '') {
                            $tok = self::normalizeToken($rawName);
                            if ($tok !== '') {
                                $tokens[$tok] = true;
                            }
                        }

                        /** @var mixed $rawId */
                        $rawId = $role->getAttribute('id');
                        if (is_string($rawId) && $rawId !== '') {
                            $tok = self::normalizeToken($rawId);
                            if ($tok !== '') {
                                $tokens[$tok] = true;
                            }
                        }
                    }

                    if ($tokens !== []) {
                        /** @var list<string> $out */
                        $out = array_keys($tokens);

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
            /** @var list<string> $cfgStrs */
            $cfgStrs = array_values(array_filter($cfg, 'is_string'));
            foreach ($cfgStrs as $n) {
                $tok = self::normalizeToken($n);
                if ($tok !== '') {
                    $out[] = $tok;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $unknown
     */
    private static function auditUnknownRoles(string $policy, array $unknown): void
    {
        try {
            if (! config('core.audit.enabled', true) || ! self::hasTable('audit_events')) {
                return;
            }
            if ($policy === '') {
                return;
            }

            /** @var AuditLogger $audit */
            $audit = app(AuditLogger::class);
            $audit->log([
                'actor_id' => null,
                'action' => 'rbac.policy.override.unknown_role',
                'category' => 'RBAC',
                'entity_type' => 'rbac.policy',
                'entity_id' => $policy,
                'ip' => null,
                'ua' => null,
                'meta' => ['unknown_roles' => $unknown],
            ]);
        } catch (\Throwable) {
            // swallow
        }
    }

    /**
     * Normalize to storage token:
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

    /**
     * @return array<string, list<string>>
     */
    public static function roleCapabilities(): array
    {
        return [
            'admin' => ['*'],
            'auditor' => ['core.audit.export', 'core.theme.view'],
            'risk_manager' => ['core.evidence.upload', 'core.evidence.delete', 'core.exports.generate'],
            'theme_manager' => ['core.theme.view', 'core.theme.manage', 'core.theme.pack.manage'],
            'theme_auditor' => ['core.theme.view'],
            'user' => [],
        ];
    }

    /**
     * @param  list<string>  $caps
     */
    public static function hasWildcard(array $caps): bool
    {
        return in_array('*', $caps, true);
    }

    public static function clearCache(): void
    {
        self::$cache = null;
        self::$cacheKey = null;
        self::$auditedUnknownRoles = [];
    }

    private static function fingerprint(): string
    {
        /** @var mixed $pol */
        $pol = config('core.rbac.policies');
        /** @var mixed $mode */
        $mode = config('core.rbac.mode');
        /** @var mixed $persist */
        $persist = config('core.rbac.persistence');
        $catalog = self::roleCatalog();
        $db = null;
        if (self::hasTable('policy_role_assignments')) {
            try {
                /** @var \Illuminate\Support\Collection<int, object> $rowsRaw */
                $rowsRaw = DB::table('policy_role_assignments')
                    ->select(['policy', 'role_id'])
                    ->orderBy('policy')
                    ->orderBy('role_id')
                    ->get();

                $dbRows = [];
                foreach ($rowsRaw as $row) {
                    $policyRaw = $row->policy ?? null;
                    $roleIdRaw = $row->role_id ?? null;
                    if (! is_string($policyRaw) || $policyRaw === '' || ! is_string($roleIdRaw) || $roleIdRaw === '') {
                        continue;
                    }
                    $dbRows[] = ['policy' => $policyRaw, 'role_id' => $roleIdRaw];
                }
                $db = $dbRows;
            } catch (\Throwable) {
                $db = null;
            }
        }

        $payload = json_encode([$pol, $mode, $persist, $catalog, $db], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = (string) microtime(true);
        }

        return hash('sha1', $payload);
    }

    /**
     * @return array<string, list<string>>|null
     */
    private static function databaseAssignments(): ?array
    {
        if (! self::hasTable('policy_role_assignments') || ! self::hasTable('policy_roles')) {
            return null;
        }

        /** @var array<string, list<string>> $map */
        $map = [];

        try {
            /** @var \Illuminate\Support\Collection<int, object> $policiesRaw */
            $policiesRaw = DB::table('policy_roles')
                ->select(['policy'])
                ->orderBy('policy')
                ->get();
        } catch (\Throwable) {
            return null;
        }

        foreach ($policiesRaw as $policyRow) {
            $policyRaw = $policyRow->policy ?? null;
            if (! is_string($policyRaw) || $policyRaw === '') {
                continue;
            }
            $policyValue = $policyRaw;
            $map[$policyValue] = [];
        }

        try {
            /** @var \Illuminate\Support\Collection<int, object> $rowsRaw */
            $rowsRaw = DB::table('policy_role_assignments')
                ->select(['policy', 'role_id'])
                ->get();
        } catch (\Throwable) {
            return null;
        }

        $hasAssignments = false;

        foreach ($rowsRaw as $row) {
            /** @var mixed $policyRaw */
            $policyRaw = $row->policy ?? null;
            $roleIdRaw = $row->role_id ?? null;
            if (! is_string($policyRaw) || $policyRaw === '' || ! is_string($roleIdRaw) || $roleIdRaw === '') {
                continue;
            }
            $policyValue = $policyRaw;
            $roleIdValue = $roleIdRaw;
            $token = self::normalizeToken($roleIdValue);
            if ($token === '') {
                continue;
            }
            if (! array_key_exists($policyValue, $map)) {
                $map[$policyValue] = [];
            }
            if (! in_array($token, $map[$policyValue], true)) {
                $map[$policyValue][] = $token;
                $hasAssignments = true;
            }
        }

        if (! $hasAssignments) {
            return null;
        }

        foreach ($map as $policy => $tokens) {
            $map[$policy] = array_values(array_unique($tokens));
        }

        return $map;
    }

    /**
     * Exposed for controllers/tests that need the raw DB-backed map without touching cached state.
     *
     * @return array<string, list<string>>|null
     */
    public static function databaseAssignmentsForController(): ?array
    {
        return self::databaseAssignments();
    }

    private static function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

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
