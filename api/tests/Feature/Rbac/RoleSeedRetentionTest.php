<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RoleSeedRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_remain_after_creating_new_role(): void
    {
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);

        $this->seed(RolesSeeder::class);

        $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead'])
            ->assertStatus(201);

        $this->assertDatabaseHas('roles', ['id' => 'role_admin']);
        $this->assertDatabaseHas('roles', ['id' => 'role_auditor']);
        $this->assertDatabaseHas('roles', ['id' => 'role_risk_manager']);
        $this->assertDatabaseHas('roles', ['id' => 'role_user']);

        $roles = Role::query()->orderBy('name')->pluck('name')->all();

        $this->assertContains('Admin', $roles);
        $this->assertContains('Auditor', $roles);
        $this->assertContains('Risk Manager', $roles);
        $this->assertContains('User', $roles);
        $this->assertContains('Compliance Lead', $roles);

        $response = $this->getJson('/rbac/roles')
            ->assertStatus(200)
            ->assertJson(fn ($json) => $json->where('ok', true)->etc())
            ->json();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('roles', $response);
        $this->assertIsArray($response['roles']);
        $this->assertContains('admin', $response['roles']);
        $this->assertContains('auditor', $response['roles']);
        $this->assertContains('risk_manager', $response['roles']);
        $this->assertContains('user', $response['roles']);
        $this->assertContains('compliance_lead', $response['roles']);
    }
}
