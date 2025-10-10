<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\PolicyMap;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DashboardKpisAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_kpis(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['role_admin'],
            ]),
        ]);

        $this->resetPolicyAssignments('core.metrics.view', ['role_admin']);

        $admin = $this->makeUser('Admin One', 'admin1@example.test');
        $this->attachNamedRole($admin, 'Admin');

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/dashboard/kpis');
        $resp->assertStatus(200);

        $kpis = $this->extractKpis($resp);

        self::assertIsArray($kpis);
        self::assertArrayHasKey('auth_activity', $kpis);
        self::assertArrayHasKey('evidence_mime', $kpis);
        self::assertArrayHasKey('admin_activity', $kpis);

        self::assertIsArray($kpis['auth_activity']);
        self::assertIsArray($kpis['evidence_mime']);
        self::assertIsArray($kpis['admin_activity']);

        foreach (['window_days', 'from', 'to', 'daily', 'totals', 'max_daily_total'] as $key) {
            self::assertArrayHasKey($key, $kpis['auth_activity']);
        }
        foreach (['total', 'by_mime'] as $key) {
            self::assertArrayHasKey($key, $kpis['evidence_mime']);
        }
        self::assertArrayHasKey('admins', $kpis['admin_activity']);
    }

    public function test_auditor_is_forbidden_from_kpis(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['role_admin'],
            ]),
        ]);

        $this->resetPolicyAssignments('core.metrics.view', ['role_admin']);

        $auditor = $this->makeUser('Auditor One', 'auditor1@example.test');
        $this->attachNamedRole($auditor, 'Auditor');

        $this->actingAs($auditor, 'sanctum')
            ->getJson('/dashboard/kpis')
            ->assertStatus(403);
    }

    /** Create a user without factories. */
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

    /** Ensure a role exists and attach to user. */
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

    /** Normalize controller response: root or { data: ... }. */
    private function extractKpis(\Illuminate\Testing\TestResponse $resp): array
    {
        $json = $resp->json();
        if (is_array($json) && array_key_exists('data', $json) && is_array($json['data'])) {
            return $json['data'];
        }

        return is_array($json) ? $json : [];
    }

    private function resetPolicyAssignments(string $policy, ?array $roles = null): void
    {
        if (Schema::hasTable('policy_role_assignments')) {
            DB::table('policy_role_assignments')->where('policy', $policy)->delete();
            if ($roles !== null) {
                $rows = [];
                $now = now('UTC')->toDateTimeString();
                foreach ($roles as $roleId) {
                    $rows[] = [
                        'policy' => $policy,
                        'role_id' => $roleId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    DB::table('policy_role_assignments')->insert($rows);
                }
            }
        }
        PolicyMap::clearCache();
    }
}
