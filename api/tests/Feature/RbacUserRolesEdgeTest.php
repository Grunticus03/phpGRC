<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacUserRolesEdgeTest extends TestCase
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
        $adminRoleId = (string) Role::query()->where('id', 'role_admin')->value('id');
        $admin->roles()->syncWithoutDetaching([$adminRoleId]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_attach_same_role_twice_is_idempotent_and_single_audit(): void
    {
        $this->actingAsAdmin();

        Role::query()->firstOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->postJson("/rbac/users/{$user->id}/roles/Auditor")->assertStatus(200);
        $this->postJson("/rbac/users/{$user->id}/roles/Auditor")->assertStatus(200);

        $count = AuditEvent::query()
            ->where('action', 'rbac.user_role.attached')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $user->id)
            ->count();

        $this->assertSame(1, $count, 'Expected exactly one canonical attached audit');
    }

    public function test_detach_non_assigned_role_no_audit(): void
    {
        $this->actingAsAdmin();

        Role::query()->firstOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $user = User::query()->create([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->deleteJson("/rbac/users/{$user->id}/roles/Auditor")->assertStatus(200);

        $count = AuditEvent::query()
            ->where('action', 'rbac.user_role.detached')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $user->id)
            ->count();

        $this->assertSame(0, $count, 'Expected zero detached audits when role was not assigned');
    }

    public function test_replace_empty_clears_roles_and_logs_removed_meta(): void
    {
        $this->actingAsAdmin();

        Role::query()->firstOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);

        $user = User::query()->create([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => bcrypt('secret'),
        ]);

        // Give a role first.
        $this->postJson("/rbac/users/{$user->id}/roles/Auditor")->assertStatus(200);

        // Replace with empty set.
        $this->putJson("/rbac/users/{$user->id}/roles", ['roles' => []])
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => []]);

        $ev = AuditEvent::query()
            ->where('action', 'rbac.user_role.replaced')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $user->id)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($ev, 'Expected a replaced audit event');
        /** @var array<string,mixed>|null $meta */
        $meta = $ev?->meta;
        $removed = is_array($meta) ? Arr::get($meta, 'removed', []) : [];
        $this->assertContains('Auditor', (array) $removed);
    }
}
