<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RoleSeedRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_defaults_persist_after_creating_role(): void
    {
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);

        $initial = $this->getJson('/rbac/roles')
            ->assertStatus(200)
            ->json();

        $this->assertIsArray($initial);
        $this->assertArrayHasKey('roles', $initial);
        $this->assertIsArray($initial['roles']);
        $this->assertContains('Admin', $initial['roles']);
        $this->assertContains('Auditor', $initial['roles']);
        $this->assertContains('Risk Manager', $initial['roles']);
        $this->assertContains('User', $initial['roles']);

        $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead'])
            ->assertStatus(201);

        $after = $this->getJson('/rbac/roles')
            ->assertStatus(200)
            ->json('roles');

        $this->assertIsArray($after);
        $this->assertContains('Admin', $after);
        $this->assertContains('Auditor', $after);
        $this->assertContains('Risk Manager', $after);
        $this->assertContains('User', $after);
        $this->assertContains('compliance-lead', $after);
    }
}
