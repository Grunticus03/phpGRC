<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
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
            'core.audit.enabled' => true,
            'core.rbac.enabled' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.persistence' => true,
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

        $etag = $this->currentSettingsEtag();

        // Change retention from default to a custom value.
        $resp1 = $this->withHeaders(['If-Match' => $etag])->postJson('/admin/settings', [
            'apply' => true,
            'audit' => ['retention_days' => 180],
        ])->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        $etag = (string) $resp1->headers->get('ETag');

        // Revert to default to exercise unset path.
        $this->withHeaders(['If-Match' => $etag])->postJson('/admin/settings', [
            'apply' => true,
            'audit' => ['retention_days' => 365],
        ])->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        // Fetch audit trail and verify setting.modified events with structured changes.
        $resp = $this->getJson('/audit?limit=50');
        $resp->assertStatus(200)->assertJson(['ok' => true]);

        /** @var array<int, array<string,mixed>> $items */
        $items = $resp->json('items') ?? [];
        $this->assertIsArray($items);

        // Pull only setting.modified events.
        $settingsEvents = array_values(array_filter($items, static fn ($i) => ($i['action'] ?? null) === 'setting.modified'));

        $this->assertNotEmpty($settingsEvents, 'Expected at least one setting.modified event');

        // Each setting.modified should include a top-level changes array with one change.
        foreach ($settingsEvents as $evt) {
            $this->assertArrayHasKey('changes', $evt);
            $this->assertIsArray($evt['changes']);
            $this->assertCount(1, $evt['changes']);
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

    public function test_settings_update_does_not_duplicate_audit_events(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $etag = $this->currentSettingsEtag();

        $this->withHeaders(['If-Match' => $etag])->postJson('/admin/settings', [
            'apply' => true,
            'audit' => ['retention_days' => 180],
        ])->assertStatus(200)->assertJson(['ok' => true, 'applied' => true]);

        $events = AuditEvent::query()
            ->where('action', 'setting.modified')
            ->orderBy('occurred_at')
            ->get();

        self::assertCount(1, $events);
    }

    private function currentSettingsEtag(): string
    {
        $response = $this->json('GET', '/admin/settings');
        $response->assertStatus(200);

        $etag = $response->headers->get('ETag');
        self::assertNotNull($etag, 'Expected ETag header from /admin/settings');

        return (string) $etag;
    }
}
