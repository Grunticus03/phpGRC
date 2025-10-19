<?php

declare(strict_types=1);

namespace App\Services\IntegrationBus;

use App\Events\IntegrationBus\AssetDiscoveryMessageReceived;
use App\Events\IntegrationBus\AssetLifecycleMessageReceived;
use App\Events\IntegrationBus\AuthProviderMessageReceived;
use App\Events\IntegrationBus\CyberMetricMessageReceived;
use App\Events\IntegrationBus\IncidentEventMessageReceived;
use App\Events\IntegrationBus\IndicatorMetricMessageReceived;
use App\Events\IntegrationBus\IntegrationBusMessageReceived;
use App\Events\IntegrationBus\VendorProfileMessageReceived;
use App\Integrations\Bus\IntegrationBusEnvelope;
use InvalidArgumentException;

use function event;

/**
 * Translates Integration Bus envelopes into domain events per payload kind.
 */
final class IntegrationBusDispatcher
{
    /**
     * @throws InvalidArgumentException when the envelope kind is unsupported.
     */
    public function dispatch(IntegrationBusEnvelope $envelope): void
    {
        $event = match ($envelope->kind) {
            'asset.discovery' => AssetDiscoveryMessageReceived::class,
            'asset.lifecycle' => AssetLifecycleMessageReceived::class,
            'incident.event' => IncidentEventMessageReceived::class,
            'vendor.profile' => VendorProfileMessageReceived::class,
            'indicator.metric' => IndicatorMetricMessageReceived::class,
            'cyber.metric' => CyberMetricMessageReceived::class,
            'auth.provider' => AuthProviderMessageReceived::class,
            default => null,
        };

        if ($event === null) {
            throw new InvalidArgumentException("Unsupported Integration Bus kind [{$envelope->kind}]");
        }

        /** @var class-string<IntegrationBusMessageReceived> $event */
        /** @psalm-suppress UnsafeInstantiation */
        event(new $event($envelope));
    }
}
