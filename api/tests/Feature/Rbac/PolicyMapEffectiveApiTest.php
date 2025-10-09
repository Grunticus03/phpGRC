<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'core.rbac.roles' => ['Admin', 'Auditor'],
            'core.rbac.policies' => [
                'core.metrics.view' => ['Admin', 'Auditor'],
                'core.rbac.view' => ['Admin'],
            ],
        ]);

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
                        'core.metrics.view' => ['admin', 'auditor'],
                        'core.rbac.view' => ['admin'],
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
                'core.rbac.view' => ['Admin'],
            ],
        ]);

        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        Role::query()->updateOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $aud = User::factory()->create();
        $aud->roles()->attach('role_auditor');
        $this->actingAs($aud, 'sanctum');

        $this->getJson('/rbac/policies/effective')->assertStatus(403);
    }
}
