<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_effective_config(): void
    {
        // Given an override
        Setting::query()->create([
            'key'   => 'core.audit.enabled',
            'value' => false,
            'type'  => 'json',
        ]);

        $res = $this->json('GET', '/api/admin/settings');
        $res->assertStatus(200)->assertJsonStructure(['ok', 'config' => ['core' => ['audit' => ['enabled']]]]);

        $data = $res->json();
        $this->assertSame(false, $data['config']['core']['audit']['enabled']);
    }

    public function test_update_sets_and_unsets_overrides(): void
    {
        // 1) Disable => persist override (explicit apply)
        $res1 = $this->json('POST', '/api/admin/settings', [
            'apply' => true,
            'audit' => ['enabled' => false],
        ]);
        $res1->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        $this->assertDatabaseHas('core_settings', [
            'key' => 'core.audit.enabled',
        ]);

        // 2) Revert to default => remove override (explicit apply)
        $res2 = $this->json('POST', '/api/admin/settings', [
            'apply' => true,
            'audit' => ['enabled' => true],
        ]);
        $res2->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        $this->assertDatabaseMissing('core_settings', [
            'key' => 'core.audit.enabled',
        ]);
    }

    public function test_update_partial_does_not_touch_other_overrides(): void
    {
        // Preload a different override.
        Setting::query()->create([
            'key'   => 'core.evidence.max_mb',
            'value' => 50,
            'type'  => 'json',
        ]);

        // Update only audit section with explicit apply.
        $this->json('POST', '/api/admin/settings', [
            'apply' => true,
            'audit' => ['retention_days' => 180],
        ])->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        // Evidence override remains.
        $this->assertDatabaseHas('core_settings', [
            'key' => 'core.evidence.max_mb',
        ]);

        // Audit override persisted (different from default 365).
        $this->assertDatabaseHas('core_settings', [
            'key' => 'core.audit.retention_days',
        ]);
    }
}

