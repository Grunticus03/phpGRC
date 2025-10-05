<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Support\Rbac\PolicyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PolicyMapFingerprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_recomputes_when_role_catalog_changes_and_audit_not_duplicated(): void
    {
        config([
            'core.rbac.mode'        => 'persist',
            'core.rbac.persistence' => true,
            'core.audit.enabled'    => true,
            // Catalog initially has only Admin
            'core.rbac.roles'       => ['Admin'],
            // Policy requests Admin + Auditor (Auditor unknown at first)
            'core.rbac.policies'    => [
                'core.metrics.view' => ['Admin', 'Auditor'],
            ],
        ]);

        // Seed catalog: only admin exists
        Role::query()->create(['id' => 'admin', 'name' => 'Admin']);

        // First compute → only 'admin' allowed; unknown 'auditor' audited once
        PolicyMap::clearCache();
        $map1 = PolicyMap::effective();
        $this->assertSame(['admin'], $map1['core.metrics.view'] ?? []);

        $count1 = AuditEvent::query()
            ->where('action', 'rbac.policy.override.unknown_role')
            ->where('entity_type', 'rbac.policy')
            ->where('entity_id', 'core.metrics.view')
            ->count();
        $this->assertSame(1, $count1, 'Should emit one unknown-role audit on first compute');

        // Now add 'auditor' to the catalog; do NOT clear the cache.
        Role::query()->create(['id' => 'auditor', 'name' => 'Auditor']);

        // Second compute should re-run due to fingerprint change and include 'auditor'
        $map2 = PolicyMap::effective();
        $this->assertSame(['admin', 'auditor'], $map2['core.metrics.view'] ?? []);

        // Unknown-role audit should NOT duplicate
        $count2 = AuditEvent::query()
            ->where('action', 'rbac.policy.override.unknown_role')
            ->where('entity_type', 'rbac.policy')
            ->where('entity_id', 'core.metrics.view')
            ->count();
        $this->assertSame(1, $count2, 'Fingerprint invalidation should not re-emit the same unknown-role audit');
    }
}
