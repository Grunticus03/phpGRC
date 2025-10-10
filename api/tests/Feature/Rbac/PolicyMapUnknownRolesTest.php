<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\AuditEvent;
use App\Support\Rbac\PolicyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PolicyMapUnknownRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable auditing and RBAC persist mode.
        config()->set('core.audit.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);

        // Simple catalog with a single known role.
        config()->set('core.rbac.roles', ['role_admin']);

        // Policy with mixed known/unknown roles. Unknowns are normalized.
        config()->set('core.rbac.policies', [
            'core.metrics.view' => ['role_admin', 'NoSuch', 'other unknown'],
        ]);

        DB::table('policy_role_assignments')->delete();

        // Start from a clean slate.
        PolicyMap::clearCache();
        AuditEvent::query()->delete();
    }

    public function test_emits_unknown_role_audit_once_per_boot(): void
    {
        // First compute -> should emit one audit row.
        $map = PolicyMap::effective();
        $this->assertArrayHasKey('core.metrics.view', $map);

        $events = AuditEvent::query()
            ->where('action', 'rbac.policy.override.unknown_role')
            ->orderBy('occurred_at')
            ->get();

        $this->assertCount(1, $events);
        $first = $events->first();
        $this->assertSame('RBAC', $first->category);
        $this->assertSame('rbac.policy', $first->entity_type);
        $this->assertSame('core.metrics.view', $first->entity_id);

        $meta = $first->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('unknown_roles', $meta);
        $this->assertSame(['nosuch', 'other_unknown'], $meta['unknown_roles']);

        // Second compute with same fingerprint -> cached -> no new audit.
        $again = PolicyMap::effective();
        $this->assertSame($map, $again);

        $eventsAfter = AuditEvent::query()
            ->where('action', 'rbac.policy.override.unknown_role')
            ->get();
        $this->assertCount(1, $eventsAfter);
    }
}
