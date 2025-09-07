<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class RbacAuthorizationHooksTest extends TestCase
{
    public function test_settings_index_allows_via_gate(): void
    {
        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonStructure(['ok', 'config']);
    }

    public function test_settings_update_allows_via_gate(): void
    {
        $payload = [
            'rbac' => [
                'enabled' => true,
                'roles' => ['Admin'],
            ],
        ];

        $this->postJson('/api/admin/settings', $payload)
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'applied' => false,
                'note' => 'stub-only',
            ]);
    }

    public function test_audit_index_allows_via_gate(): void
    {
        $this->getJson('/api/audit')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
