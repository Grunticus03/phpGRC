<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AuditLogger
{
    public function enabled(): bool
    {
        return (bool) config('core.audit.enabled', true) && Schema::hasTable('audit_events');
    }

    /**
     * @param array<string,mixed> $input
     */
    public function log(array $input): ?AuditEvent
    {
        if (!$this->enabled()) {
            return null;
        }

        $occurredAt = $this->coerceImmutable($input['occurred_at'] ?? null);

        $event              = new AuditEvent();
        $event->id          = (string) Str::ulid();
        $event->occurred_at = $occurredAt;
        $event->actor_id    = isset($input['actor_id']) && is_numeric($input['actor_id']) ? (int) $input['actor_id'] : null;
        $event->action      = (string) ($input['action'] ?? 'unknown');
        $event->category    = (string) ($input['category'] ?? 'SYSTEM');
        $event->entity_type = (string) ($input['entity_type'] ?? 'unknown');
        $event->entity_id   = (string) ($input['entity_id'] ?? '');
        $event->ip          = isset($input['ip']) ? (string) $input['ip'] : null;
        $event->ua          = isset($input['ua']) ? (string) $input['ua'] : null;
        $event->meta        = isset($input['meta']) && is_array($input['meta']) ? $input['meta'] : null;
        $event->created_at  = CarbonImmutable::now('UTC');

        $event->save();

        return $event;
    }

    private function coerceImmutable(mixed $val): CarbonImmutable
    {
        if ($val instanceof CarbonImmutable) {
            return $val->utc();
        }

        try {
            return CarbonImmutable::parse((string) $val)->utc();
        } catch (\Throwable) {
            return CarbonImmutable::now('UTC');
        }
    }
}

