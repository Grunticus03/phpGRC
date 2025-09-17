<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RbacRoleCreateAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable RBAC persistence and audit logging; allow anonymous passthrough.
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', false);

        config()->set('core.audit.enabled', true);
    }

    public function test_role_create_emits_rbac_role_created_audit(): void
    {
        $name = 'Compliance Lead';

        $res = $this->postJson('/api/rbac/roles', ['name' => $name]);
        $res->assertStatus(201)->assertJsonPath('ok', true);

        $role = $res->json('role');
        $this->assertIsArray($role);
        $this->assertArrayHasKey('id', $role);
        $this->assertArrayHasKey('name', $role);

        $event = AuditEvent::query()
            ->where('action', 'rbac.role.created')
            ->where('category', 'RBAC')
            ->where('entity_type', 'role')
            ->where('entity_id', $role['id'])
            ->first();

        $this->assertNotNull($event, 'Expected rbac.role.created event');
        $this->assertSame('RBAC', $event->category);
        $this->assertSame('role', $event->entity_type);
        $this->assertSame($role['id'], $event->entity_id);

        $meta = $event->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertSame($name, $meta['name'] ?? null);
    }
}

