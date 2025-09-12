<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class AuditLogger
{
    /**
     * @param array{
     *   actor_id?: int|null,
     *   action: string,
     *   category: string,
     *   entity_type: string,
     *   entity_id: string,
     *   ip?: string|null,
     *   ua?: string|null,
     *   meta?: array<string,mixed>|null,
     *   occurred_at?: \DateTimeInterface|string|null,
     * } $event
     */
    public function log(array $event): AuditEvent
    {
        $now = CarbonImmutable::now('UTC');

        $id = Str::ulid()->toBase32();

        // Normalize occurred_at
        $when = $now;
        $occ  = $event['occurred_at'] ?? null;
        if ($occ instanceof \DateTimeInterface) {
            $when = CarbonImmutable::instance($occ)->utc();
        } elseif (is_string($occ) && $occ !== '') {
            try {
                $when = CarbonImmutable::parse($occ)->utc();
            } catch (\Throwable) {
                $when = $now;
            }
        }

        // Trust type per PHPDoc; controllers/tests supply array|null.
        /** @var array<string,mixed>|null $meta */
        $meta = $event['meta'] ?? null;

        /** @var AuditEvent $row */
        $row = AuditEvent::query()->create([
            'id'          => $id,
            'occurred_at' => $when,
            'actor_id'    => Arr::get($event, 'actor_id'),
            'action'      => (string) $event['action'],
            'category'    => (string) $event['category'],
            'entity_type' => (string) $event['entity_type'],
            'entity_id'   => (string) $event['entity_id'],
            'ip'          => Arr::get($event, 'ip'),
            'ua'          => Arr::get($event, 'ua'),
            'meta'        => $meta,
            'created_at'  => $now,
        ]);

        return $row;
    }
}

