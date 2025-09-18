<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

final class AuditLogger
{
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
        $meta    = array_key_exists('meta', $event) && is_array($event['meta']) ? $event['meta'] : null;

        /** @var AuditEvent $row */
        $row = AuditEvent::query()->create([
            'id'          => $id,
            'occurred_at' => $when,
            'actor_id'    => $actorId,
            'action'      => $event['action'],
            'category'    => $event['category'],
            'entity_type' => $event['entity_type'],
            'entity_id'   => $event['entity_id'],
            'ip'          => $ip,
            'ua'          => $ua,
            'meta'        => $meta,
            'created_at'  => $now,
        ]);

        return $row;
    }
}

