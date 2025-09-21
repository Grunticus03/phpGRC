<?php
declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RbacDenyAuditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_emits_single_deny(): void
    {
        config([
            'core.rbac.enabled'       => true,
            'core.rbac.require_auth'  => true,   // force auth
            'core.rbac.mode'          => 'persist',
            'core.rbac.policies'      => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['Admin'],
            ]),
        ]);

        $res = $this->getJson('/api/dashboard/kpis');
        $res->assertStatus(401);

        $rows = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.deny.unauthenticated')
            ->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('route', $row->entity_type);
        $this->assertNotSame('', (string) $row->entity_id);
        $this->assertIsArray($row->meta);
        $this->assertSame('unauthenticated', $row->meta['reason'] ?? null);
        $this->assertArrayHasKey('request_id', $row->meta);
        $this->assertNotSame('', (string) $row->meta['request_id']);
    }

    public function test_role_mismatch_emits_single_deny(): void
    {
        config([
            'core.rbac.enabled'       => true,
            'core.rbac.require_auth'  => true,
            'core.rbac.mode'          => 'persist',
            'core.rbac.policies'      => array_merge(config('core.rbac.policies', []), [
                'core.settings.manage' => ['Admin'],
            ]),
        ]);

        $auditor = $this->makeUser('Auditor One', 'aud1@example.test');
        $this->attachNamedRole($auditor, 'Auditor');

        $res = $this->actingAs($auditor, 'sanctum')->getJson('/api/admin/settings');
        $res->assertStatus(403);

        $rows = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.deny.role_mismatch')
            ->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('role', $row->meta['reason'] ?? null);
        $this->assertIsArray($row->meta['required_roles'] ?? null);
        $this->assertArrayHasKey('request_id', $row->meta);
        $this->assertSame('route', $row->entity_type);
    }

    public function test_policy_denied_emits_single_deny(): void
    {
        config([
            'core.rbac.enabled'       => true,
            'core.rbac.require_auth'  => true,
            'core.rbac.mode'          => 'persist',
            // Make the route roles pass (Auditor allowed), then make the POLICY deny (Admin only)
            'core.rbac.policies'      => array_merge(config('core.rbac.policies', []), [
                'core.audit.view' => ['Admin'], // policy will deny for Auditor
            ]),
        ]);

        $user = $this->makeUser('Auditor User', 'auditor@example.test');
        $this->attachNamedRole($user, 'Auditor'); // passes route roles ['Admin','Auditor'] on /api/audit/categories

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/audit/categories');
        $res->assertStatus(403);

        $rows = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.deny.policy')
            ->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('policy', $row->meta['reason'] ?? null);
        $this->assertIsString($row->meta['policy'] ?? null);
        $this->assertArrayHasKey('request_id', $row->meta);
        $this->assertSame('route', $row->entity_type);
    }

    /** Helpers */
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
        $id = 'role_' . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));

        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['id' => $id],
            ['name' => $name]
        );

        $user->roles()->syncWithoutDetaching([$role->getKey()]);
    }
}
