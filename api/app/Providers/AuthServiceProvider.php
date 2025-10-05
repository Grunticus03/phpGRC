<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\PolicyMap;
use App\Authorization\RbacEvaluator;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [];

    public function boot(): void
    {
        $this->registerPolicies();

        foreach (PolicyMap::allKeys() as $policy) {
            Gate::define($policy, /**
             * @param User|null $user
             */
            function ($user = null) use ($policy): bool {
                return RbacEvaluator::allows($user, $policy);
            });
        }
    }
}

