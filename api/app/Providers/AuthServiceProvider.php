<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Purpose: Register RBAC gates for core capabilities (stub-only).
 * Notes:
 * - Gates return permissive defaults to keep behavior inert in Phase 4.
 * - Replace with real checks when CORE-004 enforcement lands.
 */
final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        // Model policies can be mapped here when models exist.
        // e.g., Evidence::class => \App\Policies\EvidencePolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // Settings management
        Gate::define('core.settings.manage', function ($user = null): bool {
            // TODO: enforce Admin role when RBAC is active
            return true; // stub: allow
        });

        // Evidence upload/manage
        Gate::define('core.evidence.manage', function ($user = null): bool {
            // TODO: enforce per-role and per-owner rules
            return true; // stub: allow
        });

        // Audit viewing
        Gate::define('core.audit.view', function ($user = null): bool {
            // TODO: restrict to Admin/Auditor roles
            return true; // stub: allow
        });
    }
}
