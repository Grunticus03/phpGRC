<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Lookup table for audit action labels to support search-by-label behaviour.
 */
final class ActionLabels
{
    /**
     * @var array<string, array{label: string, category: string}>
     */
    private const MAP = [
        // AUTH
        'auth.login' => ['label' => 'Login', 'category' => 'AUTH'],
        'auth.login.success' => ['label' => 'Login success', 'category' => 'AUTH'],
        'auth.login.failed' => ['label' => 'Login failed', 'category' => 'AUTH'],
        'auth.login.redirected' => ['label' => 'Login redirected', 'category' => 'AUTH'],
        'auth.logout' => ['label' => 'Logout', 'category' => 'AUTH'],
        'auth.mfa.totp.enrolled' => ['label' => 'TOTP enrolled', 'category' => 'AUTH'],
        'auth.mfa.totp.verified' => ['label' => 'TOTP verified', 'category' => 'AUTH'],
        'auth.bruteforce.locked' => ['label' => 'Brute force locked', 'category' => 'AUTH'],

        // RBAC denies
        'rbac.deny.unauthenticated' => ['label' => 'Denied: unauthenticated', 'category' => 'RBAC'],
        'rbac.deny.role_mismatch' => ['label' => 'Denied: missing required role', 'category' => 'RBAC'],
        'rbac.deny.policy' => ['label' => 'Denied: policy', 'category' => 'RBAC'],
        'rbac.deny.capability' => ['label' => 'Denied: capability', 'category' => 'RBAC'],
        'rbac.deny.unknown_policy' => ['label' => 'Denied: unknown policy', 'category' => 'RBAC'],

        // RBAC changes
        'rbac.user_role.attached' => ['label' => 'Role attached', 'category' => 'RBAC'],
        'rbac.user_role.detached' => ['label' => 'Role detached', 'category' => 'RBAC'],
        'rbac.user_role.replaced' => ['label' => 'Roles replaced', 'category' => 'RBAC'],
        'rbac.role.created' => ['label' => 'Role created', 'category' => 'RBAC'],
        'rbac.role.updated' => ['label' => 'Role updated', 'category' => 'RBAC'],
        'rbac.role.deleted' => ['label' => 'Role deleted', 'category' => 'RBAC'],
        'rbac.user.created' => ['label' => 'User created', 'category' => 'RBAC'],
        'rbac.user.deleted' => ['label' => 'User deleted', 'category' => 'RBAC'],

        // Evidence
        'evidence.uploaded' => ['label' => 'Evidence uploaded', 'category' => 'EVIDENCE'],
        'evidence.downloaded' => ['label' => 'Evidence downloaded', 'category' => 'EVIDENCE'],
        'evidence.deleted' => ['label' => 'Evidence deleted', 'category' => 'EVIDENCE'],
        'evidence.purged' => ['label' => 'Evidence purged', 'category' => 'EVIDENCE'],

        // Exports
        'export.job.created' => ['label' => 'Export started', 'category' => 'EXPORTS'],
        'export.job.completed' => ['label' => 'Export completed', 'category' => 'EXPORTS'],
        'export.job.failed' => ['label' => 'Export failed', 'category' => 'EXPORTS'],

        // Settings
        'settings.updated' => ['label' => 'Settings updated', 'category' => 'SETTINGS'],
        'setting.modified' => ['label' => 'Setting modified', 'category' => 'SETTINGS'],

        // Audit
        'audit.retention.purged' => ['label' => 'Audit purged', 'category' => 'AUDIT'],

        // Avatars
        'avatar.uploaded' => ['label' => 'Avatar uploaded', 'category' => 'AVATARS'],

        // Setup
        'setup.finished' => ['label' => 'Setup finished', 'category' => 'SETUP'],
    ];

    /**
     * Return action codes whose human-friendly labels match the query.
     *
     * @return list<string>
     */
    public static function search(string $query): array
    {
        $needle = trim(self::lower($query));
        if ($needle === '') {
            return [];
        }

        $seen = [];
        $out = [];

        foreach (self::MAP as $action => $info) {
            $actionLower = self::lower($action);
            $labelLower = self::lower($info['label']);
            $humanLower = self::lower(self::humanize($action));

            if (
                str_contains($actionLower, $needle) ||
                str_contains($labelLower, $needle) ||
                str_contains($humanLower, $needle)
            ) {
                if (! isset($seen[$action])) {
                    $seen[$action] = true;
                    $out[] = $action;
                }
            }
        }

        return $out;
    }

    /**
     * Attempt to resolve a canonical action code by case-insensitive equality.
     */
    public static function resolveExact(string $query): ?string
    {
        $needle = trim(self::lower($query));
        if ($needle === '') {
            return null;
        }

        foreach (self::MAP as $action => $_info) {
            if (self::lower($action) === $needle) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Provide the known labels for reuse.
     *
     * @return array<string, array{label: string, category: string}>
     */
    public static function labels(): array
    {
        return self::MAP;
    }

    private static function humanize(string $code): string
    {
        $replaced = str_replace(['.', '_'], ' ', $code);
        $normalized = preg_replace('/\s+/', ' ', $replaced);
        if ($normalized === null) {
            $normalized = $replaced;
        }

        $trimmed = trim($normalized);
        if ($trimmed === '') {
            return '';
        }

        return ucfirst($trimmed);
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
