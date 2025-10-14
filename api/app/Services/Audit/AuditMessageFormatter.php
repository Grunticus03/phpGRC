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
            'rbac.role.created' => self::formatRoleCreated($event, $meta),
            'rbac.role.updated' => self::formatRoleUpdated($event, $meta),
            'rbac.role.deleted' => self::formatRoleDeleted($event, $meta),
            'rbac.user.created' => self::formatUserCreated($event, $meta),
            'rbac.user.deleted' => self::formatUserDeleted($event, $meta),
            'setting.modified',
            'ui.brand.updated',
            'ui.theme.updated',
            'ui.theme.overrides.updated',
            'ui.nav.sidebar.saved',
            'ui.theme.pack.updated',
            'ui.theme.pack.deleted' => self::formatSettingModified($event, $meta),
            'evidence.uploaded' => self::formatEvidenceUploaded($event, $meta),
            'evidence.downloaded' => self::formatEvidenceDownloaded($event, $meta),
            'evidence.deleted' => self::formatEvidenceDeleted($event, $meta),
            'evidence.purged' => self::formatEvidencePurged($event, $meta),
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function resolveActor(array $meta): string
    {
        $actor = self::readString($meta, ['actor_username', 'actor_name', 'actor_email', 'actor']);

        return $actor !== '' ? $actor : 'System';
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function resolveTargetLabel(AuditEvent $event, array $meta): string
    {
        $fromMeta = self::readString($meta, ['target_username', 'target_name', 'target_email', 'target']);
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $entityType = trim($event->entity_type);
        $entityId = trim($event->entity_id);

        if ($entityType !== '' && $entityId !== '') {
            return $entityType.' '.$entityId;
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
     * @param  array<string,mixed>  $meta
     * @param  list<string>  $keys
     */
    private static function readString(array $meta, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $meta)) {
                continue;
            }
            $candidate = $meta[$key];
            if (! is_string($candidate)) {
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
     * @param  array<string,mixed>  $meta
     */
    private static function formatRoleAttached(AuditEvent $event, array $meta): string
    {
        $role = self::readString($meta, ['role', 'role_name', 'name']);
        if ($role === '') {
            return '';
        }

        $actor = self::resolveActor($meta);
        $target = self::resolveTargetLabel($event, $meta);

        return sprintf('%s role applied to %s by %s', $role, $target, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatRoleDetached(AuditEvent $event, array $meta): string
    {
        $role = self::readString($meta, ['role', 'role_name', 'name']);
        if ($role === '') {
            return '';
        }

        $actor = self::resolveActor($meta);
        $target = self::resolveTargetLabel($event, $meta);

        return sprintf('%s role removed from %s by %s', $role, $target, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatRoleReplaced(AuditEvent $event, array $meta): string
    {
        $actor = self::resolveActor($meta);
        $target = self::resolveTargetLabel($event, $meta);

        $added = self::readList($meta['added'] ?? null);
        $removed = self::readList($meta['removed'] ?? null);

        if ($added === [] && $removed === []) {
            return sprintf('Roles updated for %s by %s', $target, $actor);
        }

        $parts = [];
        if ($added !== []) {
            $parts[] = 'added '.implode(', ', $added);
        }
        if ($removed !== []) {
            $parts[] = 'removed '.implode(', ', $removed);
        }
        $delta = implode(' and ', $parts);

        return sprintf('Roles updated for %s (%s) by %s', $target, $delta, $actor);
    }

    /**
     * @return list<string>
     */
    private static function readList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
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
     * @param  array<string,mixed>  $meta
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

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatRoleUpdated(AuditEvent $event, array $meta): string
    {
        $newName = self::readString($meta, ['role', 'name', 'role_name']);
        if ($newName === '') {
            $entityId = trim($event->entity_id);
            $newName = $entityId !== '' ? $entityId : 'Role';
        }

        $previous = self::readString($meta, ['name_previous', 'previous_name', 'old_name']);
        if ($previous === '') {
            $previous = 'previous value';
        }

        $actor = self::resolveActor($meta);

        return sprintf('%s renamed from %s by %s', $newName, $previous, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatRoleDeleted(AuditEvent $event, array $meta): string
    {
        $roleName = self::readString($meta, ['role', 'role_name', 'name']);
        if ($roleName === '') {
            $entityId = trim($event->entity_id);
            $roleName = $entityId !== '' ? $entityId : 'Role';
        }

        $actor = self::resolveActor($meta);

        return sprintf('%s deleted by %s', $roleName, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatUserCreated(AuditEvent $event, array $meta): string
    {
        $target = self::resolveTargetLabel($event, $meta);
        $actor = self::resolveActor($meta);

        $roles = self::readList($meta['roles'] ?? null);
        if ($roles !== []) {
            return sprintf('%s created by %s with roles: %s', $target, $actor, implode(', ', $roles));
        }

        return sprintf('%s created by %s', $target, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatUserDeleted(AuditEvent $event, array $meta): string
    {
        $target = self::resolveTargetLabel($event, $meta);
        $actor = self::resolveActor($meta);

        return sprintf('%s deleted by %s', $target, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatSettingModified(AuditEvent $event, array $meta): string
    {
        $label = self::readString($meta, ['setting_label', 'setting_key', 'key']);
        if ($label === '') {
            $entityId = trim($event->entity_id);
            $label = $entityId !== '' ? $entityId : 'Setting';
        }

        $actor = self::resolveActor($meta);

        $changeType = self::readString($meta, ['change_type', 'action']);
        $actionVerb = self::formatSettingChangeVerb($changeType);
        $eventAction = trim($event->action);
        if ($eventAction !== '') {
            if (str_ends_with($eventAction, '.deleted')) {
                $actionVerb = 'deleted';
            } elseif (str_ends_with($eventAction, '.saved')) {
                $actionVerb = 'saved';
            }
        }

        $old = self::readString($meta, ['old_value']);
        if ($old === '') {
            $old = self::stringifyValue($meta['old'] ?? null);
        }
        $new = self::readString($meta, ['new_value']);
        if ($new === '') {
            $new = self::stringifyValue($meta['new'] ?? null);
        }

        $old = $old === '' ? 'n/a' : $old;
        $new = $new === '' ? 'n/a' : $new;

        return sprintf('%s %s %s; Old: %s - New: %s', $actor, $actionVerb, $label, $old, $new);
    }

    private static function formatSettingChangeVerb(string $changeType): string
    {
        $normalized = strtolower(trim($changeType));

        return match ($normalized) {
            'set', 'create', 'created' => 'set',
            'update', 'updated', 'modify', 'modified' => 'updated',
            'unset', 'delete', 'deleted', 'remove', 'removed', 'clear', 'cleared' => 'cleared',
            default => ($normalized !== '' ? $normalized : 'updated'),
        };
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatEvidenceUploaded(AuditEvent $event, array $meta): string
    {
        $filename = self::resolveEvidenceLabel($event, $meta);
        $actor = self::resolveActor($meta);

        return sprintf('%s uploaded to evidence by %s', $filename, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatEvidenceDownloaded(AuditEvent $event, array $meta): string
    {
        $filename = self::resolveEvidenceLabel($event, $meta);
        $actor = self::resolveActor($meta);

        $size = self::readSize($meta);
        $sizeText = $size !== null ? ' ('.$size.')' : '';

        return sprintf('%s downloaded by %s%s', $filename, $actor, $sizeText);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatEvidenceDeleted(AuditEvent $event, array $meta): string
    {
        $filename = self::resolveEvidenceLabel($event, $meta);
        $actor = self::resolveActor($meta);

        return sprintf('%s deleted by %s', $filename, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function formatEvidencePurged(AuditEvent $event, array $meta): string
    {
        $actor = self::resolveActor($meta);

        /** @var mixed $countRaw */
        $countRaw = $meta['deleted_count'] ?? null;
        $count = 0;
        if (is_int($countRaw)) {
            $count = $countRaw;
        } elseif (is_string($countRaw) && is_numeric($countRaw)) {
            $count = (int) $countRaw;
        }

        if ($count > 0) {
            return sprintf('%d evidence records purged by %s', $count, $actor);
        }

        $entityId = trim($event->entity_id);
        $target = $entityId !== '' ? $entityId : 'Evidence';

        return sprintf('%s purge requested by %s', $target, $actor);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function resolveEvidenceLabel(AuditEvent $event, array $meta): string
    {
        $filename = self::readString($meta, ['filename', 'name', 'target']);
        if ($filename !== '') {
            return $filename;
        }

        $entityId = trim($event->entity_id);

        return $entityId !== '' ? $entityId : 'Evidence';
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function readSize(array $meta): ?string
    {
        $size = null;
        foreach (['size_bytes', 'size', 'bytes'] as $key) {
            if (! array_key_exists($key, $meta)) {
                continue;
            }
            /** @var mixed $candidate */
            $candidate = $meta[$key];
            if (is_int($candidate)) {
                $size = $candidate;
                break;
            }
            if (is_string($candidate) && is_numeric($candidate)) {
                $size = (int) $candidate;
                break;
            }
        }

        if ($size === null || $size < 0) {
            return null;
        }

        return self::formatBytes($size);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $unit = 'KB';

        foreach ($units as $candidate) {
            $value /= 1024.0;
            $unit = $candidate;
            if ($value < 1024) {
                break;
            }
        }

        $roundedStr = $value >= 10.0
            ? (string) round($value)
            : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
        if ($roundedStr === '') {
            $roundedStr = '0';
        }

        return $roundedStr.' '.$unit;
    }

    private static function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            $trim = trim($value);

            return $trim;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\Throwable) {
                return '[unserializable]';
            }
        }

        return '';
    }
}
