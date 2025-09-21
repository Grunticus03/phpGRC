<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardKpisDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_from_config_are_applied_when_no_query_params(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['Admin'],
            ]),
            'core.metrics.evidence_freshness.days' => 45,
            'core.metrics.rbac_denies.window_days' => 10,
        ]);

        $admin = $this->makeUser('Admin Metrics', 'admin-metrics-defaults@example.test');
        $this->attachNamedRole($admin, 'Admin');

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard/kpis');
        $resp->assertStatus(200);

        $json = $resp->json();
        $data = is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;
        static::assertIsArray($data);

        $rbac = $data['rbac_denies'] ?? [];
        $fresh = $data['evidence_freshness'] ?? [];
        $meta = $json['meta'] ?? ($data['meta'] ?? []);

        static::assertSame(10, (int) ($rbac['window_days'] ?? -1));
        static::assertSame(45, (int) ($fresh['days'] ?? -1));

        if (is_array($meta)) {
            static::assertSame(10, (int) ($meta['window']['rbac_days'] ?? -1));
            static::assertSame(45, (int) ($meta['window']['fresh_days'] ?? -1));
        }
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
