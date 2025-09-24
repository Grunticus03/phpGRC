<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsAuditDiffsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.audit.enabled'     => true,
            'core.rbac.enabled'      => true,
            'core.rbac.mode'         => 'persist',
            'core.rbac.persistence'  => true,
            'core.rbac.require_auth' => false,
        ]);

        $this->seed(TestRbacSeeder::class);
    }

    private function makeAdmin(): User
    {
        $u = \Database\Factories\UserFactory::new()->create();
        $adminId = Role::query()->where('name', 'Admin')->value('id');
        if (is_string($adminId)) {
            $u->roles()->syncWithoutDetaching([$adminId]);
        }
        return $u;
    }

    public function test_settings_update_writes_audit_event_with_changes_and_exposes_in_api(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        // Change retention from default to a custom value.
        $this->postJson('/api/admin/settings', [
            'apply' => true,
            'audit' => ['retention_days' => 180],
        ])->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        // Revert to default to exercise unset path.
        $this->postJson('/api/admin/settings', [
            'apply' => true,
            'audit' => ['retention_days' => 365],
        ])->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        // Fetch audit trail and verify at least one settings.update with structured changes.
        $resp = $this->getJson('/api/audit?limit=50');
        $resp->assertStatus(200)->assertJson(['ok' => true]);

        /** @var array<int, array<string,mixed>> $items */
        $items = $resp->json('items') ?? [];
        $this->assertIsArray($items);

        // Pull only settings.update events.
        $settingsEvents = array_values(array_filter($items, static fn ($i) => ($i['action'] ?? null) === 'settings.update'));

        $this->assertNotEmpty($settingsEvents, 'Expected at least one settings.update event');

        // Each settings.update should include a top-level changes array.
        foreach ($settingsEvents as $evt) {
            $this->assertArrayHasKey('changes', $evt);
            $this->assertIsArray($evt['changes']);
            // Ensure our retention key is represented in at least one change set.
            $found = false;
            foreach ($evt['changes'] as $c) {
                if (($c['key'] ?? '') === 'core.audit.retention_days') {
                    $found = true;
                    $this->assertContains($c['action'], ['set', 'unset', 'update']);
                    break;
                }
            }
            $this->assertTrue($found, 'Expected core.audit.retention_days to be present in changes');
        }
    }
}

