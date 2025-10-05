<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AdminSettingsApiTest extends TestCase
{
    #[Test]
    public function index_returns_core_defaults(): void
    {
        $res = $this->getJson('/admin/settings');

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'config' => [
                    'core' => [
                        'rbac'    => ['enabled', 'roles'],
                        'audit'   => ['enabled', 'retention_days'],
                        'evidence'=> ['enabled', 'max_mb', 'allowed_mime'],
                        'avatars' => ['enabled', 'size_px', 'format'],
                    ],
                ],
            ]);
    }

    #[Test]
    public function update_accepts_flat_payload_and_echoes_accepted(): void
    {
        $payload = [
            'rbac' => [
                'enabled' => false,
                'roles'   => ['Admin', 'Auditor'],
            ],
            'audit' => [
                'enabled'        => true,
                'retention_days' => 180,
            ],
            'evidence' => [
                'enabled'      => true,
                'max_mb'       => 50,
                'allowed_mime' => ['application/pdf', 'image/png'],
            ],
            'avatars' => [
                'enabled' => true,
                'size_px' => 128,
                'format'  => 'webp',
            ],
        ];

        $res = $this->postJson('/admin/settings', $payload);

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('applied', false)
            ->assertJsonPath('note', 'stub-only')
            ->assertJsonPath('accepted.rbac.roles.0', 'Admin')
            ->assertJsonPath('accepted.audit.retention_days', 180)
            ->assertJsonPath('accepted.avatars.format', 'webp');
    }

    #[Test]
    public function update_rejects_invalid_role_entries(): void
    {
        $payload = [
            'apply' => true,
            'rbac' => [
                'roles' => ['Admin', ''],
            ],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => ['rbac' => []],
            ]);
    }

    #[Test]
    public function legacy_shape_rejects_disallowed_mime_and_returns_errors_block(): void
    {
        $payload = [
            'core' => [
                'apply' => true,
                'evidence' => [
                    'allowed_mime' => ['application/x-msdownload'],
                ],
            ],
        ];

        $res = $this->postJson('/admin/settings', $payload);

        $res->assertStatus(422)
            ->assertJsonStructure(['errors' => ['evidence' => []]])
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function update_rejects_avatar_size_other_than_128(): void
    {
        $payload = [
            'apply' => true,
            'avatars' => [
                'size_px' => 256,
            ],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['avatars' => []]]);
    }

    #[Test]
    public function update_rejects_avatar_format_other_than_webp(): void
    {
        $payload = [
            'apply' => true,
            'avatars' => [
                'format' => 'png',
            ],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['avatars' => []]]);
    }

    #[Test]
    public function update_rejects_audit_retention_out_of_range(): void
    {
        $this->postJson('/admin/settings', ['apply' => true, 'audit' => ['retention_days' => 0]])
            ->assertStatus(422);

        $this->postJson('/admin/settings', ['apply' => true, 'audit' => ['retention_days' => 10000]])
            ->assertStatus(422);
    }

    #[Test]
    public function update_rejects_evidence_max_mb_below_min(): void
    {
        $this->postJson('/admin/settings', ['apply' => true, 'evidence' => ['max_mb' => 0]])
            ->assertStatus(422);
    }
}
