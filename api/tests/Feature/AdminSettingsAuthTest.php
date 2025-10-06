<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminSettingsAuthTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdminUser(): User
    {
        $this->seed(RolesSeeder::class);

        $admin = User::query()->create([
            'name' => 'Admin One',
            'email' => 'admin1@example.com',
            'password' => bcrypt('secret'),
        ]);

        $adminRoleId = (string) Role::query()->where('id', 'role_admin')->value('id');
        $admin->roles()->syncWithoutDetaching([$adminRoleId]);

        return $admin;
    }

    public function test_unauthenticated_is_401_when_require_auth_true(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', true);

        $res = $this->getJson('/admin/settings');
        $res->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'auth.login.redirected',
            'category' => 'AUTH',
            'entity_id' => 'login_redirect',
        ]);
    }

    public function test_authenticated_admin_gets_200_when_require_auth_true(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', true);

        $admin = $this->seedAdminUser();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/admin/settings');
        $res->assertStatus(200)->assertJsonPath('ok', true);
    }

    public function test_no_auth_required_when_require_auth_false(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', false);

        $res = $this->getJson('/admin/settings');
        $res->assertStatus(200)->assertJsonPath('ok', true);
    }
}
