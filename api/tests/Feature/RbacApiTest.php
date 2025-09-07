<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class RbacApiTest extends TestCase
{
    /** @test */
    public function index_returns_roles_from_config(): void
    {
        Config::set('core.rbac.roles', ['Admin', 'Auditor', 'User']);

        $this->getJson('/api/rbac/roles')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('roles.0', 'Admin')
            ->assertJsonPath('roles.1', 'Auditor')
            ->assertJsonPath('roles.2', 'User');
    }

    /** @test */
    public function store_accepts_valid_role_and_returns_stub_202(): void
    {
        Config::set('core.rbac.roles', ['Admin', 'Auditor']);

        $this->postJson('/api/rbac/roles', ['name' => 'Operator'])
            ->assertStatus(202)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('note', 'stub-only')
            ->assertJsonPath('accepted.name', 'Operator');
    }

    /** @test */
    public function store_rejects_empty_or_too_long_name(): void
    {
        $this->postJson('/api/rbac/roles', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->postJson('/api/rbac/roles', ['name' => str_repeat('a', 65)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    /** @test */
    public function store_rejects_duplicate_against_config_list(): void
    {
        Config::set('core.rbac.roles', ['Admin', 'Auditor']);

        $this->postJson('/api/rbac/roles', ['name' => 'Admin'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    /** @test */
    public function middleware_passes_through_when_enabled_or_disabled(): void
    {
        Config::set('core.rbac.enabled', true);
        $this->getJson('/api/rbac/roles')->assertOk();

        Config::set('core.rbac.enabled', false);
        $this->getJson('/api/rbac/roles')->assertOk();
    }
}
