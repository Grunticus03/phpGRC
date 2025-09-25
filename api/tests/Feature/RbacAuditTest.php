<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable RBAC + audit with persistence.
        config()->set('core.audit.enabled', true);
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', false);

        $this->seed(RolesSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        /** @var User $admin */
        $admin = User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);

        $adminRoleId = Role::query()->where('name', 'Admin')->value('id');
        $this->assertNotNull($adminRoleId, 'Admin role must exist from seeder.');
        $admin->roles()->attach($adminRoleId);

        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_role_create_logs_audit_event(): void
    {
        $admin = $this->actingAsAdmin();

        $res = $this->postJson('/rbac/roles', ['name' => 'Compliance Lead']);
        $res->assertStatus(201)->assertJsonPath('ok', true);

        $roleId = (string) $res->json('role.id');
        $this->assertNotSame('', $roleId);

        // Canonical event
        $this->assertDatabaseHas('audit_events', [
            'category'    => 'RBAC',
            'action'      => 'rbac.role.created',
            'entity_type' => 'role',
            'entity_id'   => $roleId,
            'actor_id'    => $admin->id,
        ]);

        // Meta name
        $this->assertDatabaseHas('audit_events', [
            'action'       => 'rbac.role.created',
            'meta->name'   => 'Compliance Lead',
        ]);
    }

    public function test_attach_logs_canonical_and_alias_events(): void
    {
        $admin = $this->actingAsAdmin();

        /** @var User $u */
        $u = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Attach Auditor
        $res = $this->postJson("/rbac/users/{$u->id}/roles/Auditor");
        $res->assertOk()->assertJsonPath('ok', true);

        // Canonical
        $this->assertDatabaseHas('audit_events', [
            'category'    => 'RBAC',
            'action'      => 'rbac.user_role.attached',
            'entity_type' => 'user',
            'entity_id'   => (string) $u->id,
            'actor_id'    => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action'       => 'rbac.user_role.attached',
            'meta->role'   => 'Auditor',
        ]);

        // Alias
        $this->assertDatabaseHas('audit_events', [
            'category'    => 'RBAC',
            'action'      => 'role.attach',
            'entity_type' => 'user',
            'entity_id'   => (string) $u->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action'       => 'role.attach',
            'meta->role'   => 'Auditor',
        ]);
    }

    public function test_detach_logs_canonical_and_alias_events(): void
    {
        $admin = $this->actingAsAdmin();

        /** @var User $u */
        $u = User::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret'),
        ]);

        $auditorId = Role::query()->where('name', 'Auditor')->value('id');
        $this->assertNotNull($auditorId);
        $u->roles()->attach($auditorId);

        // Detach Auditor
        $res = $this->deleteJson("/rbac/users/{$u->id}/roles/Auditor");
        $res->assertOk()->assertJsonPath('ok', true);

        // Canonical
        $this->assertDatabaseHas('audit_events', [
            'category'    => 'RBAC',
            'action'      => 'rbac.user_role.detached',
            'entity_type' => 'user',
            'entity_id'   => (string) $u->id,
            'actor_id'    => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action'       => 'rbac.user_role.detached',
            'meta->role'   => 'Auditor',
        ]);

        // Alias
        $this->assertDatabaseHas('audit_events', [
            'action'      => 'role.detach',
            'entity_id'   => (string) $u->id,
            'category'    => 'RBAC',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action'       => 'role.detach',
            'meta->role'   => 'Auditor',
        ]);
    }

    public function test_replace_logs_canonical_event_with_before_after(): void
    {
        $admin = $this->actingAsAdmin();

        /** @var User $u */
        $u = User::query()->create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Give Carol "User" first.
        $userRoleId = Role::query()->where('name', 'User')->value('id');
        $this->assertNotNull($userRoleId);
        $u->roles()->attach($userRoleId);

        // Replace with Admin + Auditor
        $res = $this->putJson("/rbac/users/{$u->id}/roles", [
            'roles' => ['Admin', 'Auditor'],
        ]);
        $res->assertOk()->assertJsonPath('ok', true);

        // Row exists
        $this->assertDatabaseHas('audit_events', [
            'category'    => 'RBAC',
            'action'      => 'rbac.user_role.replaced',
            'entity_type' => 'user',
            'entity_id'   => (string) $u->id,
            'actor_id'    => $admin->id,
        ]);

        // Load the event and assert JSON payload precisely.
        /** @var AuditEvent $event */
        $event = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.user_role.replaced')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->orderByDesc('occurred_at')
            ->firstOrFail();

        $meta = $event->meta ?? [];
        $this->assertIsArray($meta);
        $this->assertSame(['User'], $meta['before'] ?? null);

        $after = $meta['after'] ?? null;
        $this->assertIsArray($after);
        sort($after);
        $this->assertSame(['Admin', 'Auditor'], $after);

        // Optional spot checks
        $this->assertIsArray($meta['added'] ?? []);
        $this->assertIsArray($meta['removed'] ?? []);
    }
}

