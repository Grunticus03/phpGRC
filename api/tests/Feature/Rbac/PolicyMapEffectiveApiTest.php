<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\PolicyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PolicyMapEffectiveApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_effective_policymap(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.persistence' => true,
            'core.rbac.roles' => ['Admin', 'Auditor', 'Risk Manager'],
            'core.rbac.policies' => [
                'core.metrics.view' => ['role_admin', 'role_auditor', 'role_risk_manager'],
                'core.rbac.view' => ['role_admin'],
            ],
        ]);

        $this->setPolicyAssignments('core.rbac.view', ['role_admin']);

        // Role IDs are string PKs; set explicitly to avoid DB default issues.
        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        Role::query()->updateOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $admin = User::factory()->create();
        $admin->roles()->attach('role_admin');
        $this->actingAs($admin, 'sanctum');

        $res = $this->getJson('/rbac/policies/effective');

        $res->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'data' => [
                    'policies' => [
                        'core.metrics.view' => ['role_admin', 'role_auditor', 'role_risk_manager'],
                        'core.rbac.view' => ['role_admin'],
                    ],
                ],
            ])
            ->assertJsonStructure([
                'meta' => ['generated_at', 'mode', 'persistence', 'catalog', 'fingerprint'],
            ]);
    }

    public function test_auditor_forbidden(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.persistence' => true,
            'core.rbac.roles' => ['Admin', 'Auditor'],
            'core.rbac.policies' => [
                'core.rbac.view' => ['role_admin'],
            ],
        ]);

        $this->setPolicyAssignments('core.rbac.view', ['role_admin']);

        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        Role::query()->updateOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $aud = User::factory()->create();
        $aud->roles()->attach('role_auditor');
        $this->actingAs($aud, 'sanctum');

        $this->getJson('/rbac/policies/effective')->assertStatus(403);
    }

    private function setPolicyAssignments(string $policy, array $roles): void
    {
        if (Schema::hasTable('policy_role_assignments')) {
            DB::table('policy_role_assignments')->where('policy', $policy)->delete();
            if ($roles !== []) {
                $now = now('UTC')->toDateTimeString();
                $rows = array_map(static fn (string $roleId) => [
                    'policy' => $policy,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $roles);
                DB::table('policy_role_assignments')->insert($rows);
            }
        }
        PolicyMap::clearCache();
    }
}
