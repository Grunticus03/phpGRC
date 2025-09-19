<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UserRolesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled'      => true,
            'core.rbac.mode'         => 'persist',
            'core.rbac.persistence'  => true,
            'core.audit.enabled'     => true,
            'core.rbac.require_auth' => false,
        ]);

        $this->seed(TestRbacSeeder::class);
    }

    private function makeAdmin(): User
    {
        $admin = \Database\Factories\UserFactory::new()->create();
        $adminRoleId = Role::query()->where('name', 'Admin')->value('id');
        if (is_string($adminRoleId)) {
            $admin->roles()->syncWithoutDetaching([$adminRoleId]);
        }
        return $admin;
    }

    private function makeUser(): User
    {
        return \Database\Factories\UserFactory::new()->create();
    }

    private function roleId(string $name): string
    {
        $id = Role::query()->where('name', $name)->value('id');
        return is_string($id) ? $id : ('role_' . Str::slug($name, '_'));
    }

    public function test_show_returns_user_roles(): void
    {
        $admin = $this->makeAdmin();
        $u     = $this->makeUser();

        $u->roles()->sync([$this->roleId('Auditor'), $this->roleId('User')]);

        $this->actingAs($admin, 'sanctum');
        $res = $this->getJson("/api/rbac/users/{$u->id}/roles");

        $res->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'user' => ['id' => $u->id],
            ])
            ->assertSeeText('Auditor');
    }

    public function test_attach_normalizes_name_and_writes_audit_once(): void
    {
        $admin = $this->makeAdmin();
        $u     = $this->makeUser();

        $this->actingAs($admin, 'sanctum');

        $res = $this->postJson("/api/rbac/users/{$u->id}/roles/  auDItor  ");
        $res->assertStatus(200)
            ->assertJson(['ok' => true])
            ->assertSeeText('Auditor');

        $canon = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->where('action', 'rbac.user_role.attached')
            ->get();

        $this->assertCount(1, $canon);
        $meta = $canon->first()->getAttribute('meta');
        $this->assertIsArray($meta);
        $this->assertSame('Auditor', $meta['role'] ?? null);

        $res2 = $this->postJson("/api/rbac/users/{$u->id}/roles/Auditor");
        $res2->assertStatus(200);
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'rbac.user_role.attached')
            ->where('entity_id', (string) $u->id)
            ->count());
    }

    public function test_detach_writes_audit_once_and_is_idempotent(): void
    {
        $admin = $this->makeAdmin();
        $u     = $this->makeUser();

        $u->roles()->sync([$this->roleId('Auditor')]);

        $this->actingAs($admin, 'sanctum');

        $res = $this->deleteJson("/api/rbac/users/{$u->id}/roles/AUDITOR");
        $res->assertStatus(200)
            ->assertJson(['ok' => true]);

        $canon = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->where('action', 'rbac.user_role.detached')
            ->count();

        $this->assertSame(1, $canon);

        $res2 = $this->deleteJson("/api/rbac/users/{$u->id}/roles/Auditor");
        $res2->assertStatus(200);
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'rbac.user_role.detached')
            ->where('entity_id', (string) $u->id)
            ->count());
    }

    public function test_replace_roles_returns_diff_and_audit(): void
    {
        $admin = $this->makeAdmin();
        $u     = $this->makeUser();

        $u->roles()->sync([$this->roleId('User')]);

        $this->actingAs($admin, 'sanctum');

        $payload = ['roles' => [' admin ', 'Risk   Manager']];
        $res = $this->putJson("/api/rbac/users/{$u->id}/roles", $payload);

        $res->assertStatus(200)
            ->assertJson(['ok' => true])
            ->assertSeeText('Admin')
            ->assertSeeText('Risk Manager');

        $evt = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->where('action', 'rbac.user_role.replaced')
            ->first();

        $this->assertNotNull($evt);
        $meta = $evt->getAttribute('meta');
        $this->assertIsArray($meta);
        $this->assertContains('Admin', $meta['added'] ?? []);
        $this->assertContains('Risk Manager', $meta['added'] ?? []);
        $this->assertContains('User', $meta['removed'] ?? []);
    }

    public function test_replace_rejects_duplicates_after_normalization(): void
    {
        $admin = $this->makeAdmin();
        $u     = $this->makeUser();
        $this->actingAs($admin, 'sanctum');

        $res = $this->putJson("/api/rbac/users/{$u->id}/roles", [
            'roles' => ['Auditor', '  auditor  '],
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'code' => 'VALIDATION_FAILED',
            ]);
    }

    public function test_attach_unknown_role_returns_422_with_missing_roles(): void
    {
        $admin = $this->makeAdmin();
        $u     = $this->makeUser();
        $this->actingAs($admin, 'sanctum');

        $res = $this->postJson("/api/rbac/users/{$u->id}/roles/NopeRole");
        $res->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'code' => 'ROLE_NOT_FOUND',
                'missing_roles' => ['NopeRole'],
            ]);
    }

    public function test_rbac_disabled_returns_404(): void
    {
        config(['core.rbac.enabled' => false]);

        $admin = $this->makeAdmin();
        $u     = $this->makeUser();
        $this->actingAs($admin, 'sanctum');

        $res = $this->postJson("/api/rbac/users/{$u->id}/roles/Auditor");
        $res->assertStatus(404)
            ->assertJson([
                'ok' => false,
                'code' => 'RBAC_DISABLED',
            ]);
    }

    public function test_forbidden_for_non_admin(): void
    {
        $nonAdmin = $this->makeUser();
        $u        = $this->makeUser();

        $this->actingAs($nonAdmin, 'sanctum');

        $res = $this->postJson("/api/rbac/users/{$u->id}/roles/Auditor");
        $res->assertStatus(403);
    }

    public function test_require_auth_true_returns_401_when_unauthenticated(): void
    {
        // Flip config, then reboot app so route stack picks up auth:sanctum.
        config([
            'core.rbac.require_auth' => true,
            'core.rbac.enabled'      => true,
            'core.rbac.mode'         => 'persist',
            'core.rbac.persistence'  => true,
            'core.audit.enabled'     => true,
        ]);

        $this->refreshApplication();

        // Re-run migrations and seed after reboot.
        $this->artisan('migrate', ['--force' => true]);
        $this->seed(TestRbacSeeder::class);

        $u = \Database\Factories\UserFactory::new()->create();

        $res = $this->postJson("/api/rbac/users/{$u->id}/roles/Auditor");
        $res->assertStatus(401);
    }
}

