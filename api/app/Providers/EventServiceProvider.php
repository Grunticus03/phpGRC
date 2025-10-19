<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\IntegrationBus\AssetDiscoveryMessageReceived;
use App\Events\IntegrationBus\AssetLifecycleMessageReceived;
use App\Events\IntegrationBus\AuthProviderMessageReceived;
use App\Events\IntegrationBus\CyberMetricMessageReceived;
use App\Events\IntegrationBus\IncidentEventMessageReceived;
use App\Events\IntegrationBus\IndicatorMetricMessageReceived;
use App\Events\IntegrationBus\VendorProfileMessageReceived;
use App\Events\SettingsUpdated;
use App\Listeners\Audit\RecordSettingsUpdate;
use App\Listeners\IntegrationBus\RecordIntegrationBusObservability;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        SettingsUpdated::class => [
            RecordSettingsUpdate::class,
        ],
        AssetDiscoveryMessageReceived::class => [
            RecordIntegrationBusObservability::class,
        ],
        AssetLifecycleMessageReceived::class => [
            RecordIntegrationBusObservability::class,
        ],
        IncidentEventMessageReceived::class => [
            RecordIntegrationBusObservability::class,
        ],
        VendorProfileMessageReceived::class => [
            RecordIntegrationBusObservability::class,
        ],
        IndicatorMetricMessageReceived::class => [
            RecordIntegrationBusObservability::class,
        ],
        CyberMetricMessageReceived::class => [
            RecordIntegrationBusObservability::class,
        ],
        AuthProviderMessageReceived::class => [
            RecordIntegrationBusObservability::class,
        ],
    ];

    #[\Override]
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
