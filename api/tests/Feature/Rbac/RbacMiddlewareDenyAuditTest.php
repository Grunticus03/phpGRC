<?php
declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RbacMiddlewareDenyAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_capability_off_emits_single_rbac_deny_capability(): void
    {
        config([
            'core.rbac.enabled'                        => true,
            'core.rbac.require_auth'                   => false, // testing path
            'core.capabilities.core.exports.generate'  => false,
        ]);

        $res = $this->postJson('/api/exports/test');
        $res->assertStatus(403)->assertJson([
            'ok'   => false,
            'code' => 'CAPABILITY_DISABLED',
        ]);

        $rows = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.deny.capability')
            ->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('rbac.deny.capability', $row->action);
        $this->assertSame('route', $row->entity_type);
        $this->assertNotSame('', (string) $row->entity_id);
        $this->assertIsArray($row->meta);
        $this->assertSame('core.exports.generate', $row->meta['capability'] ?? null);
        $this->assertNotSame('', (string) ($row->meta['request_id'] ?? ''));
    }
}

