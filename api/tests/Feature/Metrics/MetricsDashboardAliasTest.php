<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetricsDashboardAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_alias_endpoint_returns_same_shape_as_kpis(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['role_admin'],
            ]),
        ]);

        $admin = $this->makeUser('Admin Metrics', 'admin-metrics-alias@example.test');
        $this->attachNamedRole($admin, 'Admin');

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/metrics/dashboard');
        $resp->assertStatus(200);

        $json = $resp->json();
        $data = is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;

        self::assertIsArray($data);
        self::assertArrayHasKey('auth_activity', $data);
        self::assertArrayHasKey('evidence_mime', $data);
        self::assertArrayHasKey('admin_activity', $data);

        foreach (['window_days', 'from', 'to', 'daily', 'totals', 'max_daily_total'] as $key) {
            self::assertArrayHasKey($key, $data['auth_activity']);
        }
        foreach (['total', 'by_mime'] as $key) {
            self::assertArrayHasKey($key, $data['evidence_mime']);
        }
        self::assertArrayHasKey('admins', $data['admin_activity']);
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
        $id = 'role_'.strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));

        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['id' => $id],
            ['name' => $name]
        );

        $user->roles()->syncWithoutDetaching([$role->getKey()]);
    }
}
