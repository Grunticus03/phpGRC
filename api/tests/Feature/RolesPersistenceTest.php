<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RolesPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Force persistence path for these tests only.
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
    }

    public function test_store_persists_role_and_index_reflects_db(): void
    {
        $create = $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead']);
        $create->assertCreated()
            ->assertJson([
                'ok'   => true,
                'role' => ['name' => 'Compliance-Lead'],
            ]);

        /** @var array{role: array{id: string, name: string}} $cjson */
        $cjson = $create->json();
        self::assertMatchesRegularExpression('/^role_compliance_lead(_\d+)?$/', $cjson['role']['id']);

        $index = $this->getJson('/rbac/roles');
        $index->assertOk();

        /** @var array{roles: array<int,string>} $ijson */
        $ijson = $index->json();
        self::assertContains('Compliance-Lead', $ijson['roles']);

        self::assertTrue(Role::query()->where('name', 'Compliance-Lead')->exists());
    }

    public function test_slug_collision_results_in_incremented_id(): void
    {
        // Pre-create conflicting slug
        Role::query()->create([
            'id'   => 'role_risk_manager',
            'name' => 'Risk Manager',
        ]);

        $res = $this->postJson('/rbac/roles', ['name' => 'Risk-Manager']);
        $res->assertCreated();

        /** @var array{role: array{id: string}} $json */
        $json = $res->json();
        self::assertSame(1, preg_match('/^role_risk_manager_\d+$/', $json['role']['id']));
    }
}

