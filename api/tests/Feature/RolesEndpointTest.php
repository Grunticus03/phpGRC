<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RolesEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_echoes_stub_with_202_for_admin(): void
    {
        config([
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => false,
            'core.rbac.mode' => 'stub',
        ]);

        $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead'])
            ->assertStatus(202)
            ->assertJson([
                'ok' => true,
                'note' => 'stub-only',
                'accepted' => ['name' => 'compliance_lead'],
            ]);
    }

    public function test_stub_mode_allows_reserved_role_name(): void
    {
        config([
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => false,
            'core.rbac.mode' => 'stub',
        ]);

        $this->postJson('/rbac/roles', ['name' => 'Admin'])
            ->assertStatus(202)
            ->assertJsonPath('note', 'stub-only')
            ->assertJsonPath('accepted.name', 'admin');
    }
}
