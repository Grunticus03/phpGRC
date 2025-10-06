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
        // Apply two changes
        $res = $this->postJson('/admin/settings', [
            'apply' => true,
            'audit' => ['retention_days' => 180],
            'evidence' => ['max_mb' => 50],
        ]);
        $res->assertOk();

        // Fetch latest config audit
        $q = http_build_query([
            'category' => 'config',
            'action' => 'settings.update',
            'order' => 'desc',
            'limit' => 1,
        ]);

        $r = $this->getJson('/audit?'.$q);
        $r->assertOk()->assertJsonPath('ok', true);

        $items = $r->json('items');
        self::assertIsArray($items);
        self::assertNotEmpty($items);

        $first = $items[0];
        self::assertArrayHasKey('changes', $first);
        self::assertIsArray($first['changes']);

        $keys = array_map(
            static fn (array $c): string => (string) ($c['key'] ?? ''),
            $first['changes']
        );

        self::assertContains('core.audit.retention_days', $keys);
        self::assertContains('core.evidence.max_mb', $keys);

        // Ensure no formatting: we expect structured triples
        $sample = $first['changes'][0];
        self::assertArrayHasKey('key', $sample);
        self::assertTrue(Arr::has($sample, ['old', 'new', 'action']));
    }
}
