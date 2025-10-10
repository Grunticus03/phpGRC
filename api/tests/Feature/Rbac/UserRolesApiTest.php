<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserRolesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_replace_roles_returns_diff_and_audit(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => true,
            'core.rbac.mode' => 'persist',
            'core.audit.enabled' => true,
        ]);

        /** @var User $u */
        $u = User::factory()->create();

        Role::query()->updateOrCreate(['id' => 'role_risk_manager'], ['name' => 'risk_manager']);
        Role::query()->updateOrCreate(['id' => 'role_compliance_lead'], ['name' => 'compliance_lead']);

        $this->putJson("/rbac/users/{$u->id}/roles", ['roles' => ['risk_manager', 'Compliance Lead']])
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => ['compliance_lead', 'risk_manager']]);
    }
}
