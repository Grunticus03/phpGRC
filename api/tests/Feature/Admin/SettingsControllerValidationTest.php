<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Tests\TestCase;

final class SettingsControllerValidationTest extends TestCase
{
    public function test_rejects_invalid_avatars_size(): void
    {
        $payload = [
            'apply' => true,
            'avatars' => ['enabled' => true, 'size_px' => 96, 'format' => 'webp'],
            'rbac' => ['enabled' => true, 'roles' => ['Admin']],
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf']],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.avatars.size_px.0', fn ($v) => is_string($v));
    }

    public function test_rejects_non_webp_avatar_format(): void
    {
        $payload = [
            'apply' => true,
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'png'],
            'rbac' => ['enabled' => true, 'roles' => ['Admin']],
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf']],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.avatars.format.0', fn ($v) => is_string($v));
    }

    public function test_rejects_audit_retention_out_of_range_low(): void
    {
        $payload = [
            'apply' => true,
            'audit' => ['enabled' => true, 'retention_days' => 0],
            'rbac' => ['enabled' => true, 'roles' => ['Admin']],
            'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf']],
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.audit.retention_days.0', fn ($v) => is_string($v));
    }

    public function test_rejects_audit_retention_out_of_range_high(): void
    {
        $payload = [
            'apply' => true,
            'audit' => ['enabled' => true, 'retention_days' => 9999],
            'rbac' => ['enabled' => true, 'roles' => ['Admin']],
            'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf']],
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.audit.retention_days.0', fn ($v) => is_string($v));
    }

    public function test_rejects_evidence_max_mb_too_small(): void
    {
        $payload = [
            'apply' => true,
            'evidence' => ['enabled' => true, 'max_mb' => 0, 'allowed_mime' => ['application/pdf']],
            'rbac' => ['enabled' => true, 'roles' => ['Admin']],
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.evidence.max_mb.0', fn ($v) => is_string($v));
    }

    public function test_rejects_disallowed_evidence_mime(): void
    {
        $payload = [
            'apply' => true,
            'evidence' => [
                'enabled' => true,
                'max_mb' => 25,
                'allowed_mime' => ['application/x-msdownload']
            ],
            'rbac' => ['enabled' => true, 'roles' => ['Admin']],
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.evidence.allowed_mime.0', fn ($v) => is_string($v));
    }

    public function test_rejects_rbac_role_name_too_long(): void
    {
        $tooLong = str_repeat('A', 65);
        $payload = [
            'apply' => true,
            'rbac' => ['enabled' => true, 'roles' => ['Admin', $tooLong]],
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf']],
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.rbac.roles.0', fn ($v) => is_string($v));
    }

    public function test_legacy_shape_with_invalid_values_is_rejected(): void
    {
        $payload = [
            'core' => [
                'apply' => true,
                'avatars' => ['enabled' => true, 'size_px' => 256, 'format' => 'jpeg'],
                'audit' => ['enabled' => true, 'retention_days' => 0],
                'evidence' => ['enabled' => true, 'max_mb' => 0, 'allowed_mime' => ['application/x-msdownload']],
                'rbac' => ['enabled' => true, 'roles' => [str_repeat('B', 70)]],
            ],
        ];

        $this->postJson('/admin/settings', $payload)
            ->assertStatus(422)
            ->assertJson(fn ($json) =>
                $json->has('errors.avatars.size_px')
                     ->has('errors.avatars.format')
                     ->has('errors.audit.retention_days')
                     ->has('errors.evidence.max_mb')
                     ->has('errors.evidence.allowed_mime')
                     ->has('errors.rbac.roles')
                     ->etc()
            );
    }
}

