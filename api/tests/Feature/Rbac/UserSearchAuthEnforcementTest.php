<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UserSearchAuthEnforcementTest extends TestCase
{
    private function stubNoTables(): void
    {
        Schema::shouldReceive('hasTable')->with('core_settings')->zeroOrMoreTimes()->andReturn(false);
        Schema::shouldReceive('hasTable')->with('users')->zeroOrMoreTimes()->andReturn(false);
    }

    public function test_requires_auth_when_flag_enabled(): void
    {
        $this->stubNoTables();
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.require_auth', true);

        $resp = $this->getJson('/rbac/users/search?q=alpha');

        $resp->assertStatus(401)
            ->assertJson([
                'ok' => false,
                'code' => 'UNAUTHENTICATED',
            ]);
    }

    public function test_allows_guest_when_flag_disabled(): void
    {
        $this->stubNoTables();
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.require_auth', false);

        $resp = $this->getJson('/rbac/users/search?q=alpha');

        $resp->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'data' => [],
                'meta' => [
                    'page' => 1,
                    'per_page' => 50,
                    'total' => 0,
                    'total_pages' => 0,
                ],
            ]);
    }
}
