<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RolesEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enforce RBAC + auth so route role tags apply.
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.roles' => ['Admin', 'Auditor', 'Risk Manager', 'User'],
        ]);

        // Seed role catalog (string PKs).
        Role::query()->create(['id' => 'admin',   'name' => 'Admin']);
        Role::query()->create(['id' => 'auditor', 'name' => 'Auditor']);
        Role::query()->create(['id' => 'user',    'name' => 'User']);
    }

    private function makeUserWithRoles(array $roleNames): User
    {
        /** @var User $u */
        $u = User::query()->create([
            'name' => 'Tester ' . implode('-', $roleNames),
            'email' => uniqid('u', true) . '@example.test',
            'password' => bcrypt('secret-secret'),
        ]);

        $roleIds = Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id')
            ->all();

        $u->roles()->attach($roleIds);

        return $u;
    }

    public function test_index_lists_roles_from_config(): void
    {
        $admin = $this->makeUserWithRoles(['Admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/rbac/roles')
            ->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'roles' => ['Admin', 'Auditor', 'Risk Manager', 'User'],
            ]);
    }

    public function test_store_requires_admin_role(): void
    {
        $nonAdmin = $this->makeUserWithRoles(['User']);
        Sanctum::actingAs($nonAdmin);

        $this->postJson('/rbac/roles', ['name' => 'New Role'])
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'code' => 'FORBIDDEN']);
    }

    public function test_store_validates_name_required_and_duplicate(): void
    {
        $admin = $this->makeUserWithRoles(['Admin']);
        Sanctum::actingAs($admin);

        // Required
        $this->postJson('/rbac/roles', [])
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'code' => 'VALIDATION_FAILED',
            ])
            ->assertJsonValidationErrors(['name']);

        // Duplicate against config list
        $this->postJson('/rbac/roles', ['name' => 'Admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_echoes_stub_with_202_for_admin(): void
    {
        $admin = $this->makeUserWithRoles(['Admin']);
        Sanctum::actingAs($admin);

        $this->postJson('/rbac/roles', ['name' => 'Compliance Lead'])
            ->assertStatus(202)
            ->assertJson([
                'ok' => false,
                'note' => 'stub-only',
                'accepted' => ['name' => 'Compliance Lead'],
            ]);
    }

    public function test_unauthenticated_gets_401_when_require_auth_enabled(): void
    {
        $this->getJson('/rbac/roles')->assertStatus(401);
        $this->postJson('/rbac/roles', ['name' => 'X'])->assertStatus(401);
    }
}
