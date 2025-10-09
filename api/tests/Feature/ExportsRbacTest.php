<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ExportsRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enforce RBAC + auth in middleware during these tests.
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            // Keep persistence disabled so we hit the stub path and avoid DB/filesystem coupling.
            'core.exports.enabled' => false,
            // Capability default on; specific tests toggle it.
            'core.capabilities.core.exports.generate' => true,
        ]);

        // Minimal role catalog.
        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        Role::query()->updateOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);
        Role::query()->updateOrCreate(['id' => 'role_user'], ['name' => 'User']);
    }

    private function makeUserWithRoles(array $roleNames): User
    {
        /** @var User $u */
        $u = User::query()->create([
            'name' => 'Test '.implode('-', $roleNames),
            'email' => uniqid('u', true).'@example.test',
            'password' => bcrypt('secret-secret'),
        ]);

        $roleIds = Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id')
            ->all();

        // Attach by role_id (string PK).
        $u->roles()->attach($roleIds);

        return $u;
    }

    public function test_admin_can_create_export_when_capability_enabled(): void
    {
        $admin = $this->makeUserWithRoles(['Admin']);
        Sanctum::actingAs($admin);

        $res = $this->postJson('/exports/csv', ['params' => []]);

        $res->assertStatus(202) // create returns 202 Accepted
            ->assertJson([
                'ok' => true,
                'type' => 'csv',
            ]);
    }

    public function test_admin_blocked_when_capability_disabled(): void
    {
        config(['core.capabilities.core.exports.generate' => false]);

        $admin = $this->makeUserWithRoles(['Admin']);
        Sanctum::actingAs($admin);

        $res = $this->postJson('/exports/csv', ['params' => []]);

        $res->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'CAPABILITY_DISABLED',
                'capability' => 'core.exports.generate',
            ]);
    }

    public function test_auditor_cannot_create_export(): void
    {
        $auditor = $this->makeUserWithRoles(['Auditor']);
        Sanctum::actingAs($auditor);

        $res = $this->postJson('/exports/csv', ['params' => []]);

        $res->assertStatus(403)
            ->assertJson(['ok' => false, 'code' => 'FORBIDDEN']);
    }

    public function test_admin_and_auditor_can_check_status(): void
    {
        // Admin
        $admin = $this->makeUserWithRoles(['Admin']);
        Sanctum::actingAs($admin);
        $this->getJson('/exports/exp_stub_0001/status')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
        // Auditor
        $aud = $this->makeUserWithRoles(['Auditor']);
        Sanctum::actingAs($aud);
        $this->getJson('/exports/exp_stub_0001/status')
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_user_forbidden_from_status_and_download(): void
    {
        $user = $this->makeUserWithRoles(['User']);
        Sanctum::actingAs($user);

        $this->getJson('/exports/exp_stub_0001/status')
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'code' => 'FORBIDDEN']);

        $this->get('/exports/exp_stub_0001/download')
            ->assertStatus(403);
    }

    public function test_unauthenticated_gets_401_when_require_auth_enabled(): void
    {
        // No Sanctum user.
        $this->postJson('/exports/csv', ['params' => []])
            ->assertStatus(401);
    }
}
