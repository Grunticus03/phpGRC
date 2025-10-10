<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Support\Rbac\PolicyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PolicyMapUnknownRoleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audits_unknown_roles_once_per_policy(): void
    {
        // Enable persist mode & auditing
        config([
            'core.rbac.mode' => 'persist',
            'core.rbac.persistence' => true,
            'core.audit.enabled' => true,
            // Only "admin" exists in catalog; "ghost_role" should be audited as unknown
            'core.rbac.roles' => ['role_admin'],
            'core.rbac.policies' => [
                'core.metrics.view' => ['role_admin', 'ghost_role'],
            ],
        ]);

        // Ensure only the canonical Admin role is present in the catalog.
        Role::query()->where('id', '!=', 'role_admin')->delete();
        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);

        DB::table('policy_role_assignments')->delete();

        // Prime & compute
        PolicyMap::clearCache();
        $map = PolicyMap::effective();
        $this->assertSame(['role_admin'], $map['core.metrics.view'] ?? []);

        // One audit row emitted
        $rows = AuditEvent::query()
            ->where('action', 'rbac.policy.override.unknown_role')
            ->where('entity_type', 'rbac.policy')
            ->where('entity_id', 'core.metrics.view')
            ->get();

        $this->assertCount(1, $rows);
        $meta = (array) ($rows->first()->meta ?? []);
        $this->assertArrayHasKey('unknown_roles', $meta);
        $this->assertContains('ghost_role', (array) $meta['unknown_roles']);

        // Call again — still only one row (once per policy per boot)
        $again = PolicyMap::effective();
        $this->assertSame(['role_admin'], $again['core.metrics.view'] ?? []);
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'rbac.policy.override.unknown_role')
                ->where('entity_type', 'rbac.policy')
                ->where('entity_id', 'core.metrics.view')
                ->count()
        );
    }
}
