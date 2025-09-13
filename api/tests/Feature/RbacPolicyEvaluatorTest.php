<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\RbacEvaluator;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->seed(RolesSeeder::class);

        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret'),
        ]);

        $adminId = (string) DB::table('roles')->where('name', 'Admin')->value('id');
        $user->roles()->attach($adminId);

        $this->assertTrue(RbacEvaluator::allows($user, 'core.settings.manage'));
    }

    public function test_persist_mode_denies_user_without_required_role(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);

        $this->seed(RolesSeeder::class);

        $user = User::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->assertFalse(RbacEvaluator::allows($user, 'core.settings.manage'));
    }
}

