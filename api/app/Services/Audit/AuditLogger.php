<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Central helper to write audit events.
 * No side-effects besides insert.
 */
final class AuditLogger
{
    /**
     * @param array{
     *   occurred_at?: \DateTimeInterface|string,
     *   actor_id?: int|null,
     *   action: string,
     *   category: string,
     *   entity_type: string,
     *   entity_id: string,
     *   ip?: string|null,
     *   ua?: string|null,
     *   meta?: array<string,mixed>|null
     * } $attrs
     */
    public function log(array $attrs): AuditEvent
    {
        $now = Carbon::now('UTC');

        $occurredAt = $attrs['occurred_at'] ?? $now;
        if (!$occurredAt instanceof \DateTimeInterface) {
            $occurredAt = Carbon::parse((string) $occurredAt)->utc();
        }

        $e = new AuditEvent([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $occurredAt,
            'actor_id'    => $attrs['actor_id'] ?? null,
            'action'      => $attrs['action'],
            'category'    => $attrs['category'],
            'entity_type' => $attrs['entity_type'],
            'entity_id'   => $attrs['entity_id'],
            'ip'          => $attrs['ip']  ?? null,
            'ua'          => $attrs['ua']  ?? null,
            'meta'        => $attrs['meta'] ?? null,
            'created_at'  => $now,
        ]);

        $e->save();

        return $e;
    }
}
