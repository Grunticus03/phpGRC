<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardKpisAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_kpis(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            // Ensure Admin-only for this route regardless of overlay
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['Admin'],
            ]),
        ]);

        $admin = User::factory()->create();

        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['id' => 'role_admin'],
            ['name' => 'Admin']
        );

        $admin->roles()->syncWithoutDetaching([$role->getKey()]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/dashboard/kpis')
            ->assertStatus(200)
            ->assertJsonStructure([
                'rbac_denies' => ['window_days', 'from', 'to', 'denies', 'total', 'rate', 'daily'],
                'evidence_freshness' => ['days', 'total', 'stale', 'percent', 'by_mime'],
            ]);
    }

    public function test_auditor_is_forbidden_from_kpis(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['Admin'], // Admin-only for Phase 5
            ]),
        ]);

        $auditor = User::factory()->create();

        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['id' => 'role_auditor'],
            ['name' => 'Auditor']
        );

        $auditor->roles()->syncWithoutDetaching([$role->getKey()]);

        $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/dashboard/kpis')
            ->assertStatus(403);
    }
}
