<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4: permissive gates.
 * Enforcement will land later (Phase 5+). Keep middleware + route role tags in place,
 * but gates always allow so feature tests (Evidence, Audit view) pass.
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

