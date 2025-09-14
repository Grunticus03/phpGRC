<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacUserRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', true);

        $this->seed(RolesSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Admin One',
            'email' => 'admin1@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Attach by slug id
        $adminRoleId = (string) Role::query()->where('id', 'role_admin')->value('id');
        $admin->roles()->syncWithoutDetaching([$adminRoleId]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_list_roles_for_user_initially_empty(): void
    {
        $this->actingAsAdmin();

        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret'),
        ]);

        $res = $this->getJson("/api/rbac/users/{$user->id}/roles");
        $res->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'user' => ['id' => $user->id, 'email' => 'alice@example.com'],
                'roles' => [],
            ]);
    }

    public function test_replace_roles_with_valid_set(): void
    {
        $this->actingAsAdmin();

        // Ensure target role exists with slug id
        Role::query()->firstOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $user = User::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret'),
        ]);

        $res = $this->putJson("/api/rbac/users/{$user->id}/roles", [
            'roles' => ['Auditor'],
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'user' => ['id' => $user->id],
                'roles' => ['Auditor'],
            ]);
    }

    public function test_attach_and_detach_single_role(): void
    {
        $this->actingAsAdmin();

        Role::query()->firstOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);
        Role::query()->firstOrCreate(['id' => 'role_risk_mgr'], ['name' => 'Risk Manager']);

        $user = User::query()->create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => bcrypt('secret'),
        ]);

        $a = $this->postJson("/api/rbac/users/{$user->id}/roles/Auditor");
        $a->assertStatus(200)->assertJsonFragment(['roles' => ['Auditor']]);

        $b = $this->postJson("/api/rbac/users/{$user->id}/roles/Risk Manager");
        $b->assertStatus(200)->assertJsonFragment(['roles' => ['Auditor', 'Risk Manager']]);

        $c = $this->deleteJson("/api/rbac/users/{$user->id}/roles/Auditor");
        $c->assertStatus(200)->assertJsonFragment(['roles' => ['Risk Manager']]);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->actingAsAdmin();

        $user = User::query()->create([
            'name' => 'Dave',
            'email' => 'dave@example.com',
            'password' => bcrypt('secret'),
        ]);

        $res = $this->putJson("/api/rbac/users/{$user->id}/roles", [
            'roles' => ['NotARole'],
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'code' => 'ROLE_NOT_FOUND',
                'missing_roles' => ['NotARole'],
            ]);
    }

    public function test_endpoints_404_when_rbac_disabled(): void
    {
        config()->set('core.rbac.enabled', false);

        $this->actingAsAdmin();

        $user = User::query()->create([
            'name' => 'Erin',
            'email' => 'erin@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->getJson("/api/rbac/users/{$user->id}/roles")->assertStatus(404)->assertJson(['code' => 'RBAC_DISABLED']);
        $this->putJson("/api/rbac/users/{$user->id}/roles", ['roles' => []])->assertStatus(404)->assertJson(['code' => 'RBAC_DISABLED']);
        $this->postJson("/api/rbac/users/{$user->id}/roles/Admin")->assertStatus(404)->assertJson(['code' => 'RBAC_DISABLED']);
        $this->deleteJson("/api/rbac/users/{$user->id}/roles/Admin")->assertStatus(404)->assertJson(['code' => 'RBAC_DISABLED']);
    }
}
