<?php

declare(strict_types=1);

namespace App\Listeners\IntegrationBus;

use App\Events\IntegrationBus\IntegrationBusMessageReceived;
use App\Services\IntegrationBus\IntegrationBusObservability;

/**
 * Listener that forwards Integration Bus envelope events to the observability service.
 */
final class RecordIntegrationBusObservability
{
    public function __construct(private readonly IntegrationBusObservability $observability) {}

    public function handle(IntegrationBusMessageReceived $event): void
    {
        $this->observability->recordReceived($event->envelope);
    }
}
