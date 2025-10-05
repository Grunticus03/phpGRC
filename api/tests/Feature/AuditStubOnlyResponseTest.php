<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Verifies the stub-only behavior of /audit when persistence is disabled
 * and no business filters are applied, per Phase-4 spec.
 */
final class AuditStubOnlyResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RBAC not required for this endpoint; keep request surface minimal.
        Config::set('core.rbac.enabled', true);
        Config::set('core.rbac.require_auth', false);

        // Force audit persistence OFF to trigger stub-only path.
        Config::set('core.audit.enabled', true);
        Config::set('core.audit.persistence', false);

        // Typical default; clamp is enforced by the command and surfaced in responses.
        Config::set('core.audit.retention_days', 365);
    }

    public function test_stub_only_response_when_persistence_disabled_and_no_filters(): void
    {
        $res = $this->getJson('/audit');

        $res->assertOk()
            ->assertJson([
                'ok' => true,
                'note' => 'stub-only',
                '_retention_days' => 365,
                'items' => [],
            ])
            ->assertJsonStructure([
                '_categories',
                'filters',
                'items',
                'nextCursor',
            ]);
    }

    public function test_filters_disable_stub_note_even_without_persistence(): void
    {
        // When a business filter is present, the API should not return the stub-only note.
        $res = $this->getJson('/audit?category=RBAC');

        $res->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonMissingPath('note'); // no "stub-only" key when filters are applied
    }

    public function test_csv_export_succeeds_with_text_csv_when_persistence_disabled(): void
    {
        $res = $this->get('/audit/export.csv');

        $res->assertOk();
        $this->assertSame('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('id,occurred_at,actor_id,action,category,entity_type,entity_id,ip,ua,meta_json', (string) $res->getContent());
    }
}
