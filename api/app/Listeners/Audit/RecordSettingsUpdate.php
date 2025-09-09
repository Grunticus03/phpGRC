<?php

declare(strict_types=1);

namespace App\Listeners\Audit;

use App\Events\SettingsUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Minimal audit sink. Writes to application log.
 * Wire this in EventServiceProvider when ready.
 */
final class RecordSettingsUpdate implements ShouldQueue
{
    public function handle(SettingsUpdated $event): void
    {
        Log::info('settings.updated', [
            'actor_id' => $event->actorId,
            'occurred_at' => $event->occurredAt->toIso8601String(),
            'context' => $event->context,
            'changes' => $event->changes,
        ]);
    }
}

