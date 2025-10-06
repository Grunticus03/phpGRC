<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RolesPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_role_and_index_reflects_db(): void
    {
        config([
            'core.rbac.enabled'      => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence'  => true,
            'core.rbac.mode'         => 'persist',
        ]);

        $create = $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead']);
        $create->assertStatus(201)
               ->assertJsonPath('role.name', 'compliance-lead');

        $response = $this->getJson('/rbac/roles')
            ->assertStatus(200)
            ->json('roles');

        $this->assertIsArray($response);
        $this->assertContains('compliance-lead', $response);
        $this->assertContains('Admin', $response);
        $this->assertContains('Auditor', $response);
        $this->assertContains('Risk Manager', $response);
        $this->assertContains('User', $response);
}

    public function test_update_allows_renaming_existing_role(): void
    {
        config([
            'core.rbac.enabled'      => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence'  => true,
            'core.rbac.mode'         => 'persist',
        ]);

        $this->seed(RolesSeeder::class);

        $this->patchJson('/rbac/roles/role_admin', ['name' => 'Admin_Primary'])
            ->assertStatus(200)
            ->assertJsonPath('role.name', 'admin_primary');

        $this->assertDatabaseHas('roles', ['id' => 'role_admin', 'name' => 'admin_primary']);
    }

    public function test_update_rejects_duplicate_normalized_name(): void
    {
        config([
            'core.rbac.enabled'      => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence'  => true,
            'core.rbac.mode'         => 'persist',
        ]);

        Role::query()->create(['id' => 'role_one', 'name' => 'one']);
        Role::query()->create(['id' => 'role_two', 'name' => 'two']);

        $this->patchJson('/rbac/roles/role_two', ['name' => 'One'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_delete_removes_role_and_does_not_reseed_defaults(): void
    {
        config([
            'core.rbac.enabled'      => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence'  => true,
            'core.rbac.mode'         => 'persist',
        ]);

        $this->seed(RolesSeeder::class);

        $this->deleteJson('/rbac/roles/role_auditor')->assertStatus(200);

        $this->assertDatabaseMissing('roles', ['id' => 'role_auditor']);

        $this->postJson('/rbac/roles', ['name' => 'ComplianceTeam'])->assertStatus(201);

        $this->assertDatabaseMissing('roles', ['id' => 'role_auditor']);
    }
}
