<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SettingsValidationTest extends TestCase
{
    public function test_get_settings_ok(): void
    {
        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonStructure(['ok', 'config' => ['core' => ['rbac', 'audit', 'evidence', 'avatars']]]);
    }

    public function test_invalid_roles_rejected(): void
    {
        $payload = ['rbac' => ['roles' => []]];
        $this->postJson('/api/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'VALIDATION_FAILED']);
    }

    public function test_invalid_audit_retention_rejected(): void
    {
        $payload = ['audit' => ['retention_days' => 0]];
        $this->postJson('/api/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'VALIDATION_FAILED']);
    }

    public function test_invalid_avatars_constraints_rejected(): void
    {
        $payload = ['avatars' => ['size_px' => 64, 'format' => 'png']];
        $this->postJson('/api/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'VALIDATION_FAILED']);
    }

    public function test_evidence_allowed_mime_must_be_subset(): void
    {
        $payload = ['evidence' => ['allowed_mime' => ['application/zip']]];
        $this->postJson('/api/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'VALIDATION_FAILED']);
    }

    public function test_valid_payload_returns_stub_only(): void
    {
        $payload = [
            'rbac' => ['enabled' => true, 'roles' => ['Admin','Auditor']],
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf']],
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
        ];

        $this->postJson('/api/admin/settings', $payload)
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'applied' => false,
                'note' => 'stub-only',
            ]);
    }
}
