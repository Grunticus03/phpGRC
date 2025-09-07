<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Registers capability gates backed by role checks.
 * Gates stay permissive when RBAC is disabled.
 */
final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        // Map model policies when needed.
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('core.settings.manage', /**
         * @param  User|null  $user
         */ function ($user = null): bool {
            if (! (bool) config('core.rbac.enabled', false)) {
                return true;
            }
            return $user instanceof User && $user->hasAnyRole(['Admin']);
        });

        Gate::define('core.evidence.manage', function ($user = null): bool {
            if (! (bool) config('core.rbac.enabled', false)) {
                return true;
            }
            return $user instanceof User && $user->hasAnyRole(['Admin', 'Risk Manager']);
        });

        Gate::define('core.audit.view', function ($user = null): bool {
            if (! (bool) config('core.rbac.enabled', false)) {
                return true;
            }
            return $user instanceof User && $user->hasAnyRole(['Admin', 'Auditor']);
        });
    }
}
