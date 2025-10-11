<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Http\Middleware\RbacMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

final class SettingsAuditDiffsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Focus on audit behavior, not RBAC blocking.
        $this->withoutMiddleware(RbacMiddleware::class);
        // Queue sync so ShouldQueue listener runs inline.
        config(['queue.default' => 'sync']);
        config(['core.audit.enabled' => true]);
    }

    public function test_settings_apply_writes_audit_with_changes_and_api_exposes_changes(): void
    {
        $etag = $this->currentSettingsEtag();

        // Apply two changes
        $res = $this->withHeaders(['If-Match' => $etag])->postJson('/admin/settings', [
            'apply' => true,
            'audit' => ['retention_days' => 180],
            'evidence' => ['max_mb' => 50],
        ]);
        $res->assertOk();

        // Fetch latest config audit
        $q = http_build_query([
            'category' => 'SETTINGS',
            'action' => 'setting.modified',
            'order' => 'desc',
            'limit' => 10,
        ]);

        $r = $this->getJson('/audit?'.$q);
        $r->assertOk()->assertJsonPath('ok', true);

        $items = $r->json('items');
        self::assertIsArray($items);
        self::assertNotEmpty($items);

        $collected = [];
        foreach ($items as $item) {
            if (($item['action'] ?? '') !== 'setting.modified') {
                continue;
            }
            $changes = $item['changes'] ?? null;
            self::assertIsArray($changes);
            self::assertCount(1, $changes);
            $change = $changes[0];
            self::assertArrayHasKey('key', $change);
            self::assertTrue(Arr::has($change, ['old', 'new', 'action']));
            $collected[$change['key']] = $change;
        }

        self::assertArrayHasKey('core.audit.retention_days', $collected);
        self::assertArrayHasKey('core.evidence.max_mb', $collected);
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
