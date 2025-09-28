<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RolesPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_persists_role_and_index_reflects_db(): void
    {
        config([
            'core.rbac.enabled'      => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence'  => true,
            'core.rbac.mode'         => 'persist',
        ]);

        $create = $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead']);
        $create->assertStatus(201)
               ->assertJsonPath('role.name', 'compliance-lead');

        $this->getJson('/rbac/roles')
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => ['compliance-lead']]);
    }
}
