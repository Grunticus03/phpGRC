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
        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        Role::query()->updateOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);
    }

    public function test_attach_emits_canonical_event_with_friendly_message(): void
    {
        $u = $this->makeUser();

        $res = $this->postJson("/rbac/users/{$u->id}/roles/Auditor");
        $res->assertStatus(200)->assertJsonPath('ok', true);

        $events = AuditEvent::query()
            ->where('action', 'rbac.user_role.attached')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->get();

        $this->assertCount(1, $events);
        /** @var AuditEvent $canonical */
        $canonical = $events->first();

        $meta = $canonical->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertSame('Auditor', $meta['role'] ?? null);
        $this->assertSame('role_auditor', $meta['role_id'] ?? null);
        $this->assertArrayHasKey('before', $meta);
        $this->assertArrayHasKey('after', $meta);
        $this->assertSame('Test User', $meta['target_username'] ?? null);
        $this->assertSame('Auditor role applied to Test User by System', $meta['message'] ?? null);
    }

    public function test_detach_emits_canonical_event_without_alias(): void
    {
        $u = $this->makeUser();

        // Ensure role attached first.
        $this->postJson("/rbac/users/{$u->id}/roles/Auditor")->assertStatus(200);

        $res = $this->deleteJson("/rbac/users/{$u->id}/roles/Auditor");
        $res->assertStatus(200)->assertJsonPath('ok', true);

        $events = AuditEvent::query()
            ->where('action', 'rbac.user_role.detached')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->get();

        $this->assertCount(1, $events);
        /** @var AuditEvent $canonical */
        $canonical = $events->first();

        $meta = $canonical->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertSame('Auditor', $meta['role'] ?? null);
        $this->assertSame('role_auditor', $meta['role_id'] ?? null);
        $this->assertArrayHasKey('before', $meta);
        $this->assertArrayHasKey('after', $meta);
        $this->assertSame('Auditor role removed from Test User by System', $meta['message'] ?? null);
    }

    public function test_replace_emits_canonical_event_with_delta_message(): void
    {
        $u = $this->makeUser();

        // Replace roles with a single Admin role.
        $res = $this->putJson("/rbac/users/{$u->id}/roles", [
            'roles' => ['Admin'],
        ]);

        $res->assertStatus(200)->assertJsonPath('ok', true);

        $events = AuditEvent::query()
            ->where('action', 'rbac.user_role.replaced')
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->get();

        $this->assertCount(1, $events);
        /** @var AuditEvent $canonical */
        $canonical = $events->first();

        $meta = $canonical->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('before', $meta);
        $this->assertArrayHasKey('after', $meta);
        $this->assertArrayHasKey('added', $meta);
        $this->assertArrayHasKey('removed', $meta);
        $this->assertSame('Test User', $meta['target_username'] ?? null);
        $this->assertSame('Roles updated for Test User (added Admin) by System', $meta['message'] ?? null);
    }

    private function makeUser(): User
    {
        $u = new User;
        $u->name = 'Test User';
        $u->email = 'user+'.uniqid('', true).'@example.test';
        $u->password = Hash::make('secret123!');
        $u->save();

        return $u;
    }
}
