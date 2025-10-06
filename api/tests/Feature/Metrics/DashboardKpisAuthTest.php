<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'core.metrics.view' => ['Admin'],
            ]),
        ]);

        $admin = $this->makeUser('Admin One', 'admin1@example.test');
        $this->attachNamedRole($admin, 'Admin');

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/dashboard/kpis');
        $resp->assertStatus(200);

        $kpis = $this->extractKpis($resp);

        self::assertIsArray($kpis);
        self::assertArrayHasKey('rbac_denies', $kpis);
        self::assertArrayHasKey('evidence_freshness', $kpis);

        self::assertIsArray($kpis['rbac_denies']);
        self::assertIsArray($kpis['evidence_freshness']);

        foreach (['window_days', 'from', 'to', 'denies', 'total', 'rate', 'daily'] as $key) {
            self::assertArrayHasKey($key, $kpis['rbac_denies']);
        }
        foreach (['days', 'total', 'stale', 'percent', 'by_mime'] as $key) {
            self::assertArrayHasKey($key, $kpis['evidence_freshness']);
        }
    }

    public function test_auditor_is_forbidden_from_kpis(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['Admin'],
            ]),
        ]);

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
}
