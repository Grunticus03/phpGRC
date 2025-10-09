<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RoleValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_and_detach_normalize_and_match_case_insensitively(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => true,
            'core.rbac.mode' => 'persist',
        ]);

        /** @var User $u */
        $u = User::factory()->create();

        Role::query()->updateOrCreate(['id' => 'role_risk_mgr'], ['name' => 'Risk Manager']);

        $this->postJson("/rbac/users/{$u->id}/roles/RiSk-ManAgeR")
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => ['Risk Manager']]);

        $this->deleteJson("/rbac/users/{$u->id}/roles/RISK-MANAGER")
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => []]);
    }

    public function test_attach_accepts_canonical_slug_identifiers(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => true,
            'core.rbac.mode' => 'persist',
        ]);

        /** @var User $u */
        $u = User::factory()->create();

        Role::query()->updateOrCreate(['id' => 'role_risk_mgr'], ['name' => 'Risk Manager']);

        $this->postJson("/rbac/users/{$u->id}/roles/risk_manager")
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => ['Risk Manager']]);

        $this->assertDatabaseHas('role_user', [
            'role_id' => 'role_risk_mgr',
            'user_id' => $u->id,
        ]);
    }
}
