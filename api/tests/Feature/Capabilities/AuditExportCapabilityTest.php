<?php

declare(strict_types=1);

namespace Tests\Feature\Capabilities;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditExportCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_csv_denied_when_capability_disabled(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.audit.enabled' => true,
            // Disable only this capability
            'core.capabilities.core.audit.export' => false,
        ]);

        $resp = $this->get('/audit/export.csv');

        $resp->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'CAPABILITY_DISABLED',
                'capability' => 'core.audit.export',
            ]);

        $events = AuditEvent::query()->get();
        $this->assertCount(1, $events, 'Exactly one deny audit should be emitted');

        $e = $events->first();
        $this->assertSame('RBAC', $e->category);
        $this->assertSame('rbac.deny.capability', $e->action);
        $this->assertSame('route', $e->entity_type);
        $this->assertSame('GET /audit/export.csv', $e->entity_id);
        $this->assertIsArray($e->meta);
        $this->assertSame('core.audit.export', $e->meta['capability'] ?? null);
        $this->assertSame('capability', $e->meta['reason'] ?? null);
    }

    public function test_export_csv_allows_when_capability_enabled(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.audit.enabled' => true,
            // Enable the capability
            'core.capabilities.core.audit.export' => true,
        ]);

        $resp = $this->get('/audit/export.csv');

        $resp->assertStatus(200);
        $resp->assertHeader('Content-Type', 'text/csv');
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
