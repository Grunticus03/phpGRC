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
                'core.metrics.view' => ['Admin'],
            ]),
        ]);

        $admin = $this->makeUser('Admin Metrics', 'admin-metrics-alias@example.test');
        $this->attachNamedRole($admin, 'Admin');

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/metrics/dashboard');
        $resp->assertStatus(200);

        $json = $resp->json();
        $data = is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;

        static::assertIsArray($data);
        static::assertArrayHasKey('rbac_denies', $data);
        static::assertArrayHasKey('evidence_freshness', $data);

        foreach (['window_days','from','to','denies','total','rate','daily'] as $key) {
            static::assertArrayHasKey($key, $data['rbac_denies']);
        }
        foreach (['days','total','stale','percent','by_mime'] as $key) {
            static::assertArrayHasKey($key, $data['evidence_freshness']);
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
