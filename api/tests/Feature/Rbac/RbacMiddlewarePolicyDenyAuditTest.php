<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RbacMiddlewarePolicyDenyAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_unknown_in_persist_denies_and_emits_audit(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            // Remove the policy mapping so roles pass but policy check fails
            'core.rbac.policies' => [],
        ]);

        $admin = $this->makeUser('Admin One', 'admin1@example.test');
        $this->attachNamedRole($admin, 'Admin');

        // Route /admin/settings requires Admin role and policy core.settings.manage.
        // Roles pass, but with empty policies map PolicyMap::rolesForPolicy() returns null -> deny.
        $res = $this->actingAs($admin, 'sanctum')->postJson('/admin/settings', ['core' => []]);
        $res->assertStatus(403)->assertJson(['ok' => false, 'code' => 'FORBIDDEN']);

        $rows = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.deny.policy')
            ->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('route', $row->entity_type);
        $this->assertNotSame('', (string) $row->entity_id);
        $this->assertIsArray($row->meta);
        $this->assertSame('policy', $row->meta['reason'] ?? null);
        $this->assertSame('core.settings.manage', $row->meta['policy'] ?? null);
        $this->assertSame('persist', $row->meta['rbac_mode'] ?? null);
        $this->assertNotSame('', (string) ($row->meta['request_id'] ?? ''));
    }

    private function makeUser(string $name, string $email): User
    {
        /** @var User $user */
        $user = EloquentModel::unguarded(fn () => User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret'),
        ]));

        return $user;
    }

    private function attachNamedRole(User $user, string $name): void
    {
        $id = 'role_'.strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));

        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['id' => $id],
            ['name' => $name]
        );

        $user->roles()->syncWithoutDetaching([$role->getKey()]);
    }
}
