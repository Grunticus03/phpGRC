<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4 stubs: all gates allow.
 * Replace with real checks when enforcement lands.
 */
final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        // Map model policies when implemented.
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('core.settings.manage', fn ($user = null): bool => true);
        Gate::define('core.evidence.manage', fn ($user = null): bool => true);
        Gate::define('core.audit.view',     fn ($user = null): bool => true);
    }
}
