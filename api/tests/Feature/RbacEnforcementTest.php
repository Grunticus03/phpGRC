<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force persistence so seeder runs and FK targets exist.
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);

        // Default to enabled; individual tests can override.
        config()->set('core.rbac.enabled', true);

        // Seed default roles.
        $this->seed(RolesSeeder::class);
    }

    public function test_admin_settings_access_when_rbac_disabled_is_permitted(): void
    {
        config()->set('core.rbac.enabled', false);

        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret'),
        ]);

        Sanctum::actingAs($user);

        $res = $this->getJson('/api/admin/settings');
        $res->assertStatus(200);
    }

    public function test_admin_settings_requires_admin_role_when_rbac_enabled(): void
    {
        config()->set('core.rbac.enabled', true);

        $user = User::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Attach Admin role
        $adminId = (string) DB::table('roles')->where('name', 'Admin')->value('id');
        $this->assertNotEmpty($adminId);

        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => $adminId,
        ]);

        Sanctum::actingAs($user);

        $res = $this->getJson('/api/admin/settings');
        $res->assertStatus(200);
    }

    public function test_admin_settings_denied_without_role_when_rbac_enabled(): void
    {
        config()->set('core.rbac.enabled', true);

        $user = User::query()->create([
            'name' => 'Charlie',
            'email' => 'charlie@example.com',
            'password' => bcrypt('secret'),
        ]);

        Sanctum::actingAs($user);

        $res = $this->getJson('/api/admin/settings');
        $res->assertStatus(403);
    }

    public function test_audit_view_allows_auditor_when_rbac_enabled(): void
    {
        config()->set('core.rbac.enabled', true);

        $user = User::query()->create([
            'name' => 'Dana',
            'email' => 'dana@example.com',
            'password' => bcrypt('secret'),
        ]);

        $auditorId = (string) DB::table('roles')->where('name', 'Auditor')->value('id');
        $this->assertNotEmpty($auditorId);

        DB::table('role_user')->insert([
            'user_id' => $user->id,
            'role_id' => $auditorId,
        ]);

        Sanctum::actingAs($user);

        $res = $this->getJson('/api/audit?limit=1');
        $res->assertStatus(200);
    }
}

