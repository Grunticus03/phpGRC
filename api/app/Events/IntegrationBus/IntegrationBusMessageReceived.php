<?php

declare(strict_types=1);

namespace App\Events\IntegrationBus;

use App\Integrations\Bus\IntegrationBusEnvelope;
use App\Support\Laravel\EventDispatchable;
use App\Support\Laravel\SerializesModels;

/**
 * Base class for Integration Bus message events.
 *
 * Downstream listeners receive the fully parsed envelope and can branch on
 * `->kind`/`->event` as needed.
 */
abstract class IntegrationBusMessageReceived
{
    use EventDispatchable;
    use SerializesModels;

    public function __construct(public readonly IntegrationBusEnvelope $envelope) {}
}
