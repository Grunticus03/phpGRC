<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4 gates: allow when RBAC disabled; enforce when enabled.
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

        Gate::define('core.settings.manage', function (?User $user): bool {
            if (! config('core.rbac.enabled', false)) {
                return true;
            }
            return $user instanceof User && $user->hasAnyRole(['Admin']);
        });

        Gate::define('core.evidence.manage', function (?User $user): bool {
            if (! config('core.rbac.enabled', false)) {
                return true;
            }
            return $user instanceof User && $user->hasAnyRole(['Admin']);
        });

        Gate::define('core.audit.view', function (?User $user): bool {
            if (! config('core.rbac.enabled', false)) {
                return true;
            }
            return $user instanceof User && $user->hasAnyRole(['Admin', 'Auditor']);
        });
    }
}

