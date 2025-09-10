<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\SettingsUpdated;
use App\Models\AuditEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RecordSettingsUpdate implements ShouldQueue
{
    public function handle(SettingsUpdated $event): void
    {
        if (!config('core.audit.enabled', true)) {
            return;
        }
        if (!Schema::hasTable('audit_events')) {
            return;
        }

        $touched = [];
        foreach ($event->changes as $c) {
            $touched[] = $c['key'];
        }

        AuditEvent::query()->create([
            'id'          => Str::uuid()->toString(),
            'occurred_at' => $event->occurredAt,
            'actor_id'    => $event->actorId,
            'action'      => 'settings.update',
            'category'    => 'config',
            'entity_type' => 'core.settings',
            'entity_id'   => 'core',
            'ip'          => Arr::get($event->context, 'ip'),
            'ua'          => Arr::get($event->context, 'ua'),
            'meta'        => [
                'source'       => 'settings.apply',
                'changes'      => $event->changes,
                'touched_keys' => array_values(array_unique($touched)),
                'context'      => Arr::except($event->context, ['ip', 'ua']),
            ],
            'created_at'  => now(),
        ]);
    }
}
