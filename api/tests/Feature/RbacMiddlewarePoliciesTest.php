<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RbacMiddleware;
use App\Models\User;
use App\Support\Rbac\PolicyMap;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacMiddlewarePoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral test route that requires Admin role (role-only)
        Route::middleware([RbacMiddleware::class])
            ->get('/test/policy', static function () {
                return response()->json(['ok' => true]);
            })
            ->defaults('roles', ['Admin']);
    }

    public function test_stub_mode_allows_without_auth(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'stub');
        config()->set('core.rbac.persistence', false);
        config()->set('core.rbac.require_auth', false);

        $res = $this->getJson('/test/policy');
        $res->assertStatus(200)->assertJson(['ok' => true]);
    }

    public function test_persist_mode_requires_auth_then_allows_with_admin_role(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', true);

        // No auth -> 401
        $this->getJson('/test/policy')->assertStatus(401);

        // Auth with Admin -> 200
        $this->seed(RolesSeeder::class);

        $user = User::query()->create([
            'name' => 'Root',
            'email' => 'root@example.com',
            'password' => bcrypt('secret'),
        ]);

        $adminId = (string) DB::table('roles')->where('name', 'Admin')->value('id');
        $user->roles()->attach($adminId);

        Sanctum::actingAs($user);

        $this->getJson('/test/policy')->assertStatus(200)->assertJson(['ok' => true]);
    }

    public function test_persist_mode_forbids_when_missing_role(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', true);

        $user = User::query()->create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'password' => bcrypt('secret'),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/test/policy')->assertStatus(403);
    }

    public function test_stub_mode_allows_policy_only_without_auth(): void
    {
        // Route with policy only
        Route::middleware([RbacMiddleware::class])
            ->get('/test/policy-only', static function () {
                return response()->json(['ok' => true]);
            })
            ->defaults('policy', 'core.settings.manage');

        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'stub');
        config()->set('core.rbac.persistence', false);
        config()->set('core.rbac.require_auth', false);

        $this->getJson('/test/policy-only')->assertStatus(200)->assertJson(['ok' => true]);
    }

    public function test_persist_mode_policy_only_enforces_roles_from_policy_map(): void
    {
        // Route with policy only
        Route::middleware([RbacMiddleware::class])
            ->get('/test/policy-only-2', static function () {
                return response()->json(['ok' => true]);
            })
            ->defaults('policy', 'core.settings.manage');

        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', true);

        // Unauthenticated -> 401
        $this->getJson('/test/policy-only-2')->assertStatus(401);

        // With Admin role -> 200
        $this->seed(RolesSeeder::class);
        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret'),
        ]);
        $adminId = (string) DB::table('roles')->where('name', 'Admin')->value('id');
        $user->roles()->attach($adminId);
        Sanctum::actingAs($user);

        $this->getJson('/test/policy-only-2')->assertStatus(200)->assertJson(['ok' => true]);

        // User without Admin -> 403
        $other = User::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($other);
        $this->getJson('/test/policy-only-2')->assertStatus(403);
    }

    public function test_persist_mode_role_passes_but_policy_fails_due_to_override(): void
    {
        // Route with both role and policy
        Route::middleware([RbacMiddleware::class])
            ->get('/test/role-and-policy', static function () {
                return response()->json(['ok' => true]);
            })
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'core.settings.manage');

        // Override policy map to require Risk Manager instead of Admin
        DB::table('policy_role_assignments')->where('policy', 'core.settings.manage')->delete();
        DB::table('policy_role_assignments')->insert([
            'policy' => 'core.settings.manage',
            'role_id' => 'role_risk_manager',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        PolicyMap::clearCache();

        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', true);

        $this->seed(RolesSeeder::class);

        $user = User::query()->create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => bcrypt('secret'),
        ]);
        $adminId = (string) DB::table('roles')->where('name', 'Admin')->value('id');
        $riskId = (string) DB::table('roles')->where('name', 'Risk Manager')->value('id');

        // Has Admin so role check passes, but lacks Risk Manager so policy fails.
        $user->roles()->attach($adminId);
        Sanctum::actingAs($user);
        $this->getJson('/test/role-and-policy')->assertStatus(403);

        // Add Risk Manager, now policy passes.
        $user->roles()->attach($riskId);
        $this->getJson('/test/role-and-policy')->assertStatus(200)->assertJson(['ok' => true]);
    }
}
