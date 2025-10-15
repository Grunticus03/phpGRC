<?php

declare(strict_types=1);

namespace App\Events;

use App\Support\Laravel\EventDispatchable;
use App\Support\Laravel\SerializesModels;
use Carbon\CarbonInterface;

/**
 * Emitted after settings are applied.
 */
final class SettingsUpdated
{
    use EventDispatchable;
    use SerializesModels;

    /**
     * @param  array<int, array{key:string, old:mixed, new:mixed, action:string}>  $changes
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        public readonly ?int $actorId,
        public readonly array $changes,
        public readonly array $context,
        public readonly CarbonInterface $occurredAt,
    ) {}
}
