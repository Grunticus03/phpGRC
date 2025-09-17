<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RbacUserRolesAuditTest extends TestCase
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

        // Seed roles table for tests.
        Role::query()->create(['id' => 'role_admin', 'name' => 'Admin']);
        Role::query()->create(['id' => 'role_auditor', 'name' => 'Auditor']);
    }

    public function test_attach_emits_canonical_and_alias_events(): void
    {
        $u = $this->makeUser();

        $res = $this->postJson("/api/rbac/users/{$u->id}/roles/Auditor");
        $res->assertStatus(200)->assertJsonPath('ok', true);

        $canonical = AuditEvent::query()
            ->where('action', 'rbac.user_role.attached')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->latest('occurred_at')
            ->first();

        $alias = AuditEvent::query()
            ->where('action', 'role.attach')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($canonical, 'Expected rbac.user_role.attached event');
        $this->assertNotNull($alias, 'Expected role.attach alias event');

        $meta = $canonical->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertSame('Auditor', $meta['role'] ?? null);
        $this->assertSame('role_auditor', $meta['role_id'] ?? null);
        $this->assertArrayHasKey('before', $meta);
        $this->assertArrayHasKey('after', $meta);
    }

    public function test_detach_emits_canonical_and_alias_events(): void
    {
        $u = $this->makeUser();

        // Ensure role attached first.
        $this->postJson("/api/rbac/users/{$u->id}/roles/Auditor")->assertStatus(200);

        $res = $this->deleteJson("/api/rbac/users/{$u->id}/roles/Auditor");
        $res->assertStatus(200)->assertJsonPath('ok', true);

        $canonical = AuditEvent::query()
            ->where('action', 'rbac.user_role.detached')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->latest('occurred_at')
            ->first();

        $alias = AuditEvent::query()
            ->where('action', 'role.detach')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($canonical, 'Expected rbac.user_role.detached event');
        $this->assertNotNull($alias, 'Expected role.detach alias event');

        $meta = $canonical->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertSame('Auditor', $meta['role'] ?? null);
        $this->assertSame('role_auditor', $meta['role_id'] ?? null);
        $this->assertArrayHasKey('before', $meta);
        $this->assertArrayHasKey('after', $meta);
    }

    public function test_replace_emits_canonical_and_alias_events(): void
    {
        $u = $this->makeUser();

        // Replace roles with a single Admin role.
        $res = $this->putJson("/api/rbac/users/{$u->id}/roles", [
            'roles' => ['Admin'],
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);

        $canonical = AuditEvent::query()
            ->where('action', 'rbac.user_role.replaced')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->latest('occurred_at')
            ->first();

        $alias = AuditEvent::query()
            ->where('action', 'role.replace')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($canonical, 'Expected rbac.user_role.replaced event');
        $this->assertNotNull($alias, 'Expected role.replace alias event');

        $meta = $canonical->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('before', $meta);
        $this->assertArrayHasKey('after', $meta);
        $this->assertArrayHasKey('added', $meta);
        $this->assertArrayHasKey('removed', $meta);
    }

    private function makeUser(): User
    {
        $u = new User();
        $u->name = 'Test User';
        $u->email = 'user+' . uniqid('', true) . '@example.test';
        $u->password = Hash::make('secret123!');
        $u->save();

        return $u;
        }
}

