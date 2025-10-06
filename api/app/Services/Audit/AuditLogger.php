<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class AuditLogger
{
    /**
     * @var array<string, string>
     */
    private const ACTION_ALIASES = [
        'role.attach'            => 'rbac.user_role.attached',
        'role.attach_attempt'    => 'rbac.user_role.attached',
        'role.detach'            => 'rbac.user_role.detached',
        'role.detach_attempt'    => 'rbac.user_role.detached',
        'role.replace'           => 'rbac.user_role.replaced',
        'role.replace_attempt'   => 'rbac.user_role.replaced',
        'rbac.user_role.attach'  => 'rbac.user_role.attached',
        'rbac.user_role.detach'  => 'rbac.user_role.detached',
        'rbac.user_role.replace' => 'rbac.user_role.replaced',
    ];

    /**
     * @param array{
     *   actor_id?: int|null,
     *   action: non-empty-string,
     *   category: non-empty-string,
     *   entity_type: non-empty-string,
     *   entity_id: non-empty-string,
     *   ip?: string|null,
     *   ua?: string|null,
     *   meta?: array<string,mixed>|null,
     *   occurred_at?: DateTimeInterface|string|null
     * } $event
     */
    public function log(array $event): AuditEvent
    {
        $now = CarbonImmutable::now('UTC');
        $id  = Str::ulid()->toBase32();

        // Normalize occurred_at
        $when = $now;
        $occ  = $event['occurred_at'] ?? null;
        if ($occ instanceof DateTimeInterface) {
            $when = CarbonImmutable::instance($occ)->utc();
        } elseif (is_string($occ) && $occ !== '') {
            try {
                $when = CarbonImmutable::parse($occ)->utc();
            } catch (\Throwable) {
                $when = $now;
            }
        }

        $actorId = array_key_exists('actor_id', $event) && is_int($event['actor_id']) ? $event['actor_id'] : null;
        $ip      = array_key_exists('ip', $event) && is_string($event['ip']) ? $event['ip'] : null;
        $ua      = array_key_exists('ua', $event) && is_string($event['ua']) ? $event['ua'] : null;
        $metaRaw = array_key_exists('meta', $event) && is_array($event['meta']) ? $event['meta'] : null;

        $action = $this->canonicalAction($event['action']);

        $meta = $metaRaw;
        if (is_array($meta)) {
            /** @var array<string,mixed> $meta */
            $meta = Arr::whereNotNull($meta);
        }

        $attributes = [
            'id'          => $id,
            'occurred_at' => $when,
            'actor_id'    => $actorId,
            'action'      => $action,
            'category'    => $event['category'],
            'entity_type' => $event['entity_type'],
            'entity_id'   => $event['entity_id'],
            'ip'          => $ip,
            'ua'          => $ua,
            'meta'        => $meta,
            'created_at'  => $now,
        ];

        $preview = new AuditEvent();
        $preview->fill($attributes);

        $message = AuditMessageFormatter::format($preview);
        if ($message !== '') {
            $metaForInsert           = is_array($meta) ? $meta : [];
            $metaForInsert['message'] = $message;
            $attributes['meta']       = $metaForInsert;
        }

        /** @var AuditEvent $row */
        $row = AuditEvent::query()->create($attributes);

        return $row;
    }

    private function canonicalAction(string $action): string
    {
        $key = strtolower($action);
        return self::ACTION_ALIASES[$key] ?? $action;
    }
}

