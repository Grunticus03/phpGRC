<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Rbac\RbacEvaluator;
use App\Support\Rbac\PolicyMap;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [];

    public function boot(): void
    {
        $this->registerPolicies();

        foreach (PolicyMap::policyKeys() as $policy) {
            Gate::define(
                $policy,
                function (?User $user) use ($policy): bool {
                    return RbacEvaluator::allows($user, $policy);
                }
            );
        }
    }
}
