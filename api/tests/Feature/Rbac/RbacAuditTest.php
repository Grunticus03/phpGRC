<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacAuditTest extends TestCase
{
    use RefreshDatabase;

    private function bootRbacAudit(): User
    {
        Config::set('core.audit.enabled', true);
        Config::set('core.rbac.enabled', true);
        Config::set('core.rbac.mode', 'persist');
        Config::set('core.rbac.require_auth', false);

        $this->seed(RolesSeeder::class);

        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);

        $adminRoleId = Role::query()->where('name', 'Admin')->value('id');
        $this->assertNotNull($adminRoleId);
        $admin->roles()->sync([$adminRoleId]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_role_store_emits_audit_event(): void
    {
        $admin = $this->bootRbacAudit();

        $res = $this->postJson('/rbac/roles', ['name' => 'QA-Team']);
        $res->assertStatus(201)->assertJson(['ok' => true]);

        /** @var AuditEvent|null $ev */
        $ev = AuditEvent::query()->where('action', 'rbac.role.created')->first();
        $this->assertNotNull($ev);
        $this->assertSame('RBAC', $ev->category);
        $this->assertSame($admin->id, $ev->actor_id);
        $this->assertSame('role', $ev->entity_type);
        $this->assertNotSame('', $ev->entity_id);
    }

    public function test_attach_emits_audit_event(): void
    {
        $this->bootRbacAudit();

        $target = User::query()->create([
            'name' => 'User A',
            'email' => 'usera@example.com',
            'password' => bcrypt('secret'),
        ]);

        $res = $this->postJson("/rbac/users/{$target->id}/roles/Auditor");
        $res->assertStatus(200)->assertJson(['ok' => true]);

        /** @var AuditEvent|null $ev */
        $ev = AuditEvent::query()->where('action', 'rbac.user_role.attached')->first();
        $this->assertNotNull($ev);
        $this->assertSame('RBAC', $ev->category);
        $this->assertSame('user', $ev->entity_type);
        $this->assertSame((string) $target->id, $ev->entity_id);
        $this->assertIsArray($ev->meta);
        $this->assertSame('Auditor', $ev->meta['role'] ?? null);
    }

    public function test_detach_emits_audit_event(): void
    {
        $this->bootRbacAudit();

        $target = User::query()->create([
            'name' => 'User B',
            'email' => 'userb@example.com',
            'password' => bcrypt('secret'),
        ]);

        $auditorId = Role::query()->where('name', 'Auditor')->value('id');
        $this->assertNotNull($auditorId);
        $target->roles()->sync([$auditorId]);

        $res = $this->deleteJson("/rbac/users/{$target->id}/roles/Auditor");
        $res->assertStatus(200)->assertJson(['ok' => true]);

        /** @var AuditEvent|null $ev */
        $ev = AuditEvent::query()->where('action', 'rbac.user_role.detached')->first();
        $this->assertNotNull($ev);
        $this->assertSame('RBAC', $ev->category);
        $this->assertSame('user', $ev->entity_type);
        $this->assertSame((string) $target->id, $ev->entity_id);
    }

    public function test_replace_emits_audit_event(): void
    {
        $this->bootRbacAudit();

        $target = User::query()->create([
            'name' => 'User C',
            'email' => 'userc@example.com',
            'password' => bcrypt('secret'),
        ]);

        $payload = ['roles' => ['Admin', 'Auditor']];
        $res = $this->putJson("/rbac/users/{$target->id}/roles", $payload);
        $res->assertStatus(200)->assertJson(['ok' => true]);

        /** @var AuditEvent|null $ev */
        $ev = AuditEvent::query()->where('action', 'rbac.user_role.replaced')->first();
        $this->assertNotNull($ev);
        $this->assertSame('RBAC', $ev->category);
        $this->assertSame('user', $ev->entity_type);
        $this->assertSame((string) $target->id, $ev->entity_id);
        $this->assertIsArray($ev->meta);
        $this->assertIsArray($ev->meta['after'] ?? null);
    }
}

