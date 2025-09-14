<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacAuthGateTest extends TestCase
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
            'name'     => 'Admin One',
            'email'    => 'admin1@example.com',
            'password' => bcrypt('secret'),
        ]);

        Role::query()->firstOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        $admin->roles()->syncWithoutDetaching(['role_admin']);

        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_unauthenticated_access_is_401_when_require_auth_true(): void
    {
        $this->getJson('/api/audit')
            ->assertStatus(401);
    }

    public function test_authenticated_access_is_200_when_require_auth_true(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/audit')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }
}
