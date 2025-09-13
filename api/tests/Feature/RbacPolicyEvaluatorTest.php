<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\RbacEvaluator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class RbacPolicyEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_stub_mode_allows_any_policy(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'stub');
        config()->set('core.rbac.persistence', false);

        $this->assertTrue(RbacEvaluator::allows(null, 'nonexistent.policy.key'));
        $this->assertTrue(RbacEvaluator::allows(null, 'core.settings.manage'));
    }

    public function test_persist_mode_denies_without_user(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);

        $this->assertFalse(RbacEvaluator::allows(null, 'core.settings.manage'));
    }

    public function test_persist_mode_allows_user_with_required_role(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);

        /** @var User $user */
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')
            ->once()
            ->with(['Admin'])
            ->andReturn(true);

        $this->assertTrue(RbacEvaluator::allows($user, 'core.settings.manage'));
    }

    public function test_persist_mode_denies_user_without_required_role(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);

        /** @var User $user */
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasAnyRole')
            ->once()
            ->with(['Admin'])
            ->andReturn(false);

        $this->assertFalse(RbacEvaluator::allows($user, 'core.settings.manage'));
    }
}

