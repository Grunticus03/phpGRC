<?php

declare(strict_types=1);

namespace Tests\Feature\Meterics; // deliberate: keep namespace unique to avoid collisions

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardKpisAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_kpis(): void
    {
        // Enforce auth + RBAC for this route
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => ['core.metrics.view' => ['Admin']],
        ]);

        $admin = User::factory()->create();
        $this->attachNamedRole($admin, 'Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/dashboard/kpis')
            ->assertStatus(200);
    }

    public function test_auditor_is_forbidden_from_kpis(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => ['core.metrics.view' => ['Admin']], // Admin-only for Phase 5
        ]);

        $auditor = User::factory()->create();
        $this->attachNamedRole($auditor, 'Auditor');

        $this->actingAs($auditor, 'sanctum')
            ->getJson('/api/dashboard/kpis')
            ->assertStatus(403);
    }

    /**
     * Ensure a role with human-readable slug id exists and attach to user.
     */
    private function attachNamedRole(User $user, string $name): void
    {
        $id = 'role_' . Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->toString();

        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['id' => $id],
            ['name' => $name]
        );

        $user->roles()->syncWithoutDetaching([$role->getKey()]);
    }
}
