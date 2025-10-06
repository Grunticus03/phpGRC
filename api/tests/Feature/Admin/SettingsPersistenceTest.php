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
        // Persist/override using upsert semantics.
        Setting::query()->updateOrCreate(
            ['key' => 'core.audit.enabled'],
            ['value' => 'false', 'type' => 'json'] // JSON literal false
        );

        $res = $this->json('GET', '/admin/settings');
        $res->assertStatus(200)->assertJsonStructure(['ok', 'config' => ['core' => ['audit' => ['enabled']]]]);

        $data = $res->json();
        $this->assertSame(false, $data['config']['core']['audit']['enabled']);
    }

    public function test_update_sets_and_persists_overrides(): void
    {
        // 1) Disable => persist override (explicit apply)
        $res1 = $this->json('POST', '/admin/settings', [
            'apply' => true,
            'audit' => ['enabled' => false],
        ]);
        $res1->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        $this->assertDatabaseHas('core_settings', [
            'key' => 'core.audit.enabled',
            // stored as boolean "0" with type bool by the service
            'type' => 'bool',
            'value' => '0',
        ]);

        // 2) Revert to default (true) => still persist override in DB (write-only semantics)
        $res2 = $this->json('POST', '/admin/settings', [
            'apply' => true,
            'audit' => ['enabled' => true],
        ]);
        $res2->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        // Row remains, updated to true
        $this->assertDatabaseHas('core_settings', [
            'key' => 'core.audit.enabled',
            'type' => 'bool',
            'value' => '1',
        ]);
    }

    public function test_update_partial_does_not_touch_other_overrides(): void
    {
        // Preload a different override using upsert.
        Setting::query()->updateOrCreate(
            ['key' => 'core.evidence.max_mb'],
            ['value' => '50', 'type' => 'json'] // JSON number 50
        );

        // Update only audit section with explicit apply.
        $this->json('POST', '/admin/settings', [
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
