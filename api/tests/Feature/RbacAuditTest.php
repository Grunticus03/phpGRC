<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable RBAC persistence and Audit
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', false); // keep middleware simple in tests
        config()->set('core.audit.enabled', true);

        $this->seed(RolesSeeder::class);

        // Admin actor
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);

        $adminRoleId = Role::query()->where('name', 'Admin')->value('id') ?? 'role_admin';
        if (!Role::query()->whereKey($adminRoleId)->exists()) {
            Role::query()->create(['id' => $adminRoleId, 'name' => 'Admin']);
        }
        $admin->roles()->syncWithoutDetaching([$adminRoleId]);

        Sanctum::actingAs($admin);
    }

    public function test_role_store_writes_audit_event(): void
    {
        $res = $this->postJson('/api/rbac/roles', ['name' => 'Compliance Lead']);
        $res->assertStatus(201)->assertJsonPath('ok', true);

        $count = DB::table('audit_events')->where('action', 'rbac.role.created')->where('category', 'RBAC')->count();
        $this->assertGreaterThanOrEqual(1, $count, 'Expected at least one RBAC role.created audit event');
    }

    public function test_user_roles_attach_and_detach_write_audit_events(): void
    {
        $target = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret'),
        ]);

        $adminRoleName = 'Admin';
        $this->postJson("/api/rbac/users/{$target->id}/roles/{$adminRoleName}")
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        // canonical + alias
        $this->assertSame(1, DB::table('audit_events')->where('action', 'rbac.user_role.attached')->where('entity_id', (string) $target->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'role.attach')->where('entity_id', (string) $target->id)->count());

        $this->deleteJson("/api/rbac/users/{$target->id}/roles/{$adminRoleName}")
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertSame(1, DB::table('audit_events')->where('action', 'rbac.user_role.detached')->where('entity_id', (string) $target->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'role.detach')->where('entity_id', (string) $target->id)->count());
    }

    public function test_user_roles_replace_writes_audit_event(): void
    {
        $target = User::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->putJson("/api/rbac/users/{$target->id}/roles", ['roles' => []])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertSame(1, DB::table('audit_events')->where('action', 'rbac.user_role.replaced')->where('entity_id', (string) $target->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'role.replace')->where('entity_id', (string) $target->id)->count());
    }
}

