<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

final class SettingsControllerTest extends TestCase
{
    public function test_get_settings_returns_defaults(): void
    {
        $response = $this->getJson('/admin/settings');

        $response->assertOk();

        $response->assertJson(fn (AssertableJson $json) =>
            $json->where('ok', true)
                 ->has('config.core.rbac', fn ($j) =>
                     $j->where('enabled', true)
                       ->whereType('roles', 'array')
                 )
                 ->has('config.core.audit', fn ($j) =>
                     $j->where('enabled', true)
                       ->whereType('retention_days', 'integer')
                 )
                 ->has('config.core.evidence', fn ($j) =>
                     $j->where('enabled', true)
                       ->whereType('max_mb', 'integer')
                       ->whereType('allowed_mime', 'array')
                 )
                 ->has('config.core.avatars', fn ($j) =>
                     $j->where('enabled', true)
                       ->where('size_px', 128)
                       ->where('format', 'webp')
                 )
        );
    }

    public function test_post_settings_accepts_spec_shape_and_normalizes(): void
    {
        $payload = [
            'rbac' => ['enabled' => true, 'roles' => ['Admin','Auditor','Risk Manager','User']],
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf','image/png','image/jpeg','text/plain']],
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
        ];

        $response = $this->postJson('/admin/settings', $payload);

        $response->assertOk();

        $response->assertJson(fn (AssertableJson $json) =>
            $json->where('ok', true)
                 ->where('applied', false)
                 ->where('note', 'stub-only')
                 ->has('accepted', fn ($j) =>
                     $j->hasAll(['rbac','audit','evidence','avatars'])
                       ->where('avatars.size_px', 128)
                       ->where('avatars.format', 'webp')
                 )
        );
    }

    public function test_post_settings_accepts_legacy_shape_and_normalizes(): void
    {
        $payload = [
            'core' => [
                'rbac' => ['enabled' => true, 'roles' => ['Admin','Auditor','Risk Manager','User']],
                'audit' => ['enabled' => true, 'retention_days' => 365],
                'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf','image/png','image/jpeg','text/plain']],
                'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
            ],
        ];

        $response = $this->postJson('/admin/settings', $payload);

        $response->assertOk();

        $response->assertJson(fn (AssertableJson $json) =>
            $json->where('ok', true)
                 ->where('applied', false)
                 ->where('note', 'stub-only')
                 ->has('accepted', fn ($j) =>
                     $j->hasAll(['rbac','audit','evidence','avatars'])
                       ->where('avatars.size_px', 128)
                       ->where('avatars.format', 'webp')
                 )
        );
    }
}
