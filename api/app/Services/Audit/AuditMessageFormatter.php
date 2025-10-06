<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;

final class AuditMessageFormatter
{
    public static function format(AuditEvent $event): string
    {
        $meta = $event->meta;
        $meta = is_array($meta) ? $meta : [];

        $action = $event->action;
        if ($action === '') {
            return '';
        }

        return match ($action) {
            'rbac.user_role.attached' => self::formatRoleAttached($event, $meta),
            'rbac.user_role.detached' => self::formatRoleDetached($event, $meta),
            'rbac.user_role.replaced' => self::formatRoleReplaced($event, $meta),
            'rbac.role.created'       => self::formatRoleCreated($event, $meta),
            default                   => '',
        };
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function resolveActor(array $meta): string
    {
        $actor = self::readString($meta, ['actor_username', 'actor_name', 'actor_email', 'actor']);
        return $actor !== '' ? $actor : 'System';
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function resolveTargetLabel(AuditEvent $event, array $meta): string
    {
        $fromMeta = self::readString($meta, ['target_username', 'target_name', 'target_email', 'target']);
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $entityType = trim($event->entity_type);
        $entityId   = trim($event->entity_id);

        if ($entityType !== '' && $entityId !== '') {
            return $entityType . ' ' . $entityId;
        }
        if ($entityType !== '') {
            return $entityType;
        }
        if ($entityId !== '') {
            return $entityId;
        }

        return 'target';
    }

    /**
     * @param array<string,mixed> $meta
     * @param list<string>        $keys
     */
    private static function readString(array $meta, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $candidate = $meta[$key];
            if (!is_string($candidate)) {
                continue;
            }
            $trim = trim($candidate);
            if ($trim !== '') {
                return $trim;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function formatRoleAttached(AuditEvent $event, array $meta): string
    {
        $role = self::readString($meta, ['role', 'role_name', 'name']);
        if ($role === '') {
            return '';
        }

        $actor  = self::resolveActor($meta);
        $target = self::resolveTargetLabel($event, $meta);

        return sprintf('%s role applied to %s by %s', $role, $target, $actor);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function formatRoleDetached(AuditEvent $event, array $meta): string
    {
        $role = self::readString($meta, ['role', 'role_name', 'name']);
        if ($role === '') {
            return '';
        }

        $actor  = self::resolveActor($meta);
        $target = self::resolveTargetLabel($event, $meta);

        return sprintf('%s role removed from %s by %s', $role, $target, $actor);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function formatRoleReplaced(AuditEvent $event, array $meta): string
    {
        $actor  = self::resolveActor($meta);
        $target = self::resolveTargetLabel($event, $meta);

        $added   = self::readList($meta['added'] ?? null);
        $removed = self::readList($meta['removed'] ?? null);

        if ($added === [] && $removed === []) {
            return sprintf('Roles updated for %s by %s', $target, $actor);
        }

        $parts = [];
        if ($added !== []) {
            $parts[] = 'added ' . implode(', ', $added);
        }
        if ($removed !== []) {
            $parts[] = 'removed ' . implode(', ', $removed);
        }
        $delta = implode(' and ', $parts);

        return sprintf('Roles updated for %s (%s) by %s', $target, $delta, $actor);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function readList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $trim = trim($item);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function formatRoleCreated(AuditEvent $event, array $meta): string
    {
        $roleName = self::readString($meta, ['role', 'role_name', 'name']);
        if ($roleName === '') {
            $entityId = trim($event->entity_id);
            $roleName = $entityId !== '' ? $entityId : 'Role';
        }

        $actor = self::resolveActor($meta);

        return sprintf('%s created by %s', $roleName, $actor);
    }
}
