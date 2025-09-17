<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\SettingsUpdated;
use App\Listeners\Audit\RecordSettingsUpdate;
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
    ];

    #[\Override]
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

