<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Emitted after settings are applied.
 */
final class SettingsUpdated
{
    use Dispatchable;
    use SerializesModels;

    /** @param array<int, array{key:string, old:mixed, new:mixed, action:string}> $changes */
    public function __construct(
        public readonly ?int $actorId,
        public readonly array $changes,
        public readonly array $context,
        public readonly Carbon $occurredAt,
    ) {
    }
}

