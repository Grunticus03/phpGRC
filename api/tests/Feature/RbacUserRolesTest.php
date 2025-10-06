<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RbacUserRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_and_detach_single_role(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => true,
            'core.rbac.mode' => 'persist',
        ]);

        /** @var User $u */
        $u = User::factory()->create();

        Role::query()->create(['id' => 'role_risk_manager', 'name' => 'risk-manager']);

        $this->postJson("/rbac/users/{$u->id}/roles/risk-manager")
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => ['risk-manager']]);

        $this->deleteJson("/rbac/users/{$u->id}/roles/risk-manager")
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => []]);
    }
}
