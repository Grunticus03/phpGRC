<?php

declare(strict_types=1);

namespace Tests\Feature\Capabilities;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EvidenceUploadCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_denied_when_capability_disabled(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.audit.enabled' => true,
            // Disable only this capability
            'core.capabilities.core.evidence.upload' => false,
        ]);

        // No file needed; middleware blocks before controller validation.
        $resp = $this->post('/evidence', []);

        $resp->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'CAPABILITY_DISABLED',
                'capability' => 'core.evidence.upload',
            ]);

        $events = AuditEvent::query()->get();
        $this->assertCount(1, $events, 'Exactly one deny audit should be emitted');

        $e = $events->first();
        $this->assertSame('RBAC', $e->category);
        $this->assertSame('rbac.deny.capability', $e->action);
        $this->assertSame('route', $e->entity_type);
        $this->assertSame('POST /evidence', $e->entity_id);
        $this->assertIsArray($e->meta);
        $this->assertSame('core.evidence.upload', $e->meta['capability'] ?? null);
        $this->assertSame('capability', $e->meta['reason'] ?? null);
    }
}

