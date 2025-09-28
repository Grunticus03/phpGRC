<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_valid_role_and_returns_stub_202(): void
    {
        config([
            'core.rbac.require_auth' => false,
            'core.rbac.persistence'  => false,
            'core.rbac.mode'         => 'stub',
        ]);

        $res = $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead']);

        $res->assertStatus(202)
            ->assertJson([
                'ok'       => true,
                'note'     => 'stub-only',
                'accepted' => ['name' => 'Compliance-Lead'],
            ]);
    }
}
