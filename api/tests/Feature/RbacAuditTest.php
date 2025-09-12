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

        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', true);
        config()->set('core.audit.enabled', true);

        $this->seed(RolesSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Admin One',
            'email' => 'admin1@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Ensure admin role exists with slug id
        Role::query()->firstOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);

        // Attach by slug id
        $adminRoleId = (string) Role::query()->where('id', 'role_admin')->value('id');
        $admin->roles()->syncWithoutDetaching([$adminRoleId]);

        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_replace_writes_audit_event(): void
    {
        $this->actingAsAdmin();

        Role::query()->firstOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->putJson("/api/rbac/users/{$user->id}/roles", [
            'roles' => ['Auditor'],
        ])->assertStatus(200);

        /** @var AuditEvent|null $evt */
        $evt = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'role.replace')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $user->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($evt, 'Expected an audit event for role.replace');
        $this->assertIsArray($evt->meta);
        $this->assertSame(['Auditor'], $evt->meta['after'] ?? null);
        $this->assertSame(['Auditor'], $evt->meta['added'] ?? null);
        $this->assertSame([], $evt->meta['removed'] ?? null);
    }

    public function test_attach_logs_once_when_changing(): void
    {
        $this->actingAsAdmin();

        Role::query()->firstOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $user = User::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret'),
        ]);

        // First attach creates one audit row
        $this->postJson("/api/rbac/users/{$user->id}/roles/Auditor")->assertStatus(200);

        // Idempotent second attach should not add another audit row
        $this->postJson("/api/rbac/users/{$user->id}/roles/Auditor")->assertStatus(200);

        $count = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'role.attach')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $user->id)
            ->count();

        $this->assertSame(1, $count, 'Expected exactly one role.attach audit event');
    }

    public function test_detach_logs_when_role_present(): void
    {
        $this->actingAsAdmin();

        Role::query()->firstOrCreate(['id' => 'role_risk_mgr'], ['name' => 'Risk Manager']);

        $user = User::query()->create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->postJson("/api/rbac/users/{$user->id}/roles/Risk Manager")->assertStatus(200);

        $this->deleteJson("/api/rbac/users/{$user->id}/roles/Risk Manager")->assertStatus(200);

        /** @var AuditEvent|null $evt */
        $evt = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'role.detach')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $user->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($evt, 'Expected an audit event for role.detach');
        $this->assertSame('Risk Manager', $evt->meta['role'] ?? null);
        $this->assertContains('Risk Manager', $evt->meta['before'] ?? []);
        $this->assertNotContains('Risk Manager', $evt->meta['after'] ?? []);
    }
}

