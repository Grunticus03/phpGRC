<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\PolicyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RolePoliciesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => true,
            'core.rbac.mode' => 'persist',
            'core.audit.enabled' => true,
        ]);

        Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        Role::query()->updateOrCreate(['id' => 'role_auditor'], ['name' => 'Auditor']);
        Role::query()->updateOrCreate(['id' => 'role_theme_manager'], ['name' => 'theme_manager']);

        DB::table('policy_role_assignments')->truncate();
        PolicyMap::clearCache();
    }

    public function test_index_returns_labels_and_roles_without_role_prefix(): void
    {
        DB::table('policy_role_assignments')->insert([
            [
                'policy' => 'core.settings.manage',
                'role_id' => 'role_admin',
                'created_at' => now('UTC')->toDateTimeString(),
                'updated_at' => now('UTC')->toDateTimeString(),
            ],
            [
                'policy' => 'rbac.roles.manage',
                'role_id' => 'role_admin',
                'created_at' => now('UTC')->toDateTimeString(),
                'updated_at' => now('UTC')->toDateTimeString(),
            ],
        ]);

        self::assertSame(['role_admin'], PolicyMap::rolesForPolicy('rbac.roles.manage'));

        $response = $this->getJson('/rbac/policies')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'data' => [
                    'policies' => [
                        ['policy', 'label', 'description', 'roles'],
                    ],
                ],
                'meta' => ['mode', 'persistence', 'policy_count', 'role_catalog'],
            ]);

        $policies = collect($response->json('data.policies'));
        $settingsPolicy = $policies->firstWhere('policy', 'core.settings.manage');

        self::assertIsArray($settingsPolicy);
        self::assertSame('Manage core settings', $settingsPolicy['label']);
        self::assertSame(['admin'], $settingsPolicy['roles']);
    }

    public function test_show_returns_assigned_policies_for_role(): void
    {
        DB::table('policy_role_assignments')->insert([
            [
                'policy' => 'core.audit.view',
                'role_id' => 'role_auditor',
                'created_at' => now('UTC')->toDateTimeString(),
                'updated_at' => now('UTC')->toDateTimeString(),
            ],
            [
                'policy' => 'core.audit.export',
                'role_id' => 'role_auditor',
                'created_at' => now('UTC')->toDateTimeString(),
                'updated_at' => now('UTC')->toDateTimeString(),
            ],
        ]);

        $this->getJson('/rbac/roles/auditor/policies')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('role.key', 'auditor')
            ->assertJsonPath('policies', ['core.audit.export', 'core.audit.view', 'ui.theme.view']);
    }

    public function test_update_replaces_assignments_and_writes_audit(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\RbacMiddleware::class);

        DB::table('policy_role_assignments')->insert([
            [
                'policy' => 'core.settings.manage',
                'role_id' => 'role_admin',
                'created_at' => now('UTC')->toDateTimeString(),
                'updated_at' => now('UTC')->toDateTimeString(),
            ],
        ]);

        /** @var User $actor */
        $actor = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.test']);
        Sanctum::actingAs($actor);
        $actor->roles()->sync(['role_admin']);

        $response = $this->putJson('/rbac/roles/admin/policies', [
            'policies' => ['core.audit.view', 'core.metrics.view'],
        ]);
        $response->assertStatus(200)
            ->assertJsonPath('policies', ['core.audit.view', 'core.metrics.view']);

        $stored = DB::table('policy_role_assignments')
            ->where('role_id', 'role_admin')
            ->pluck('policy')
            ->sort()
            ->values()
            ->all();

        self::assertSame(['core.audit.view', 'core.metrics.view'], $stored);

        $audit = DB::table('audit_events')
            ->where('action', 'rbac.role.policies.updated')
            ->first();

        self::assertNotNull($audit);
        self::assertSame('role_admin', $audit->entity_id);
        self::assertSame($actor->id, $audit->actor_id);
    }

    public function test_update_returns_stub_note_when_persistence_disabled(): void
    {
        config()->set('core.rbac.persistence', false);
        config()->set('core.rbac.mode', 'stub');

        $this->putJson('/rbac/roles/admin/policies', ['policies' => ['core.settings.manage']])
            ->assertStatus(202)
            ->assertJsonPath('note', 'stub-only');
    }
}
