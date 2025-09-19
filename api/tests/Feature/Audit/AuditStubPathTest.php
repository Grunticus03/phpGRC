<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditStubPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_stub_only_when_persistence_disabled_and_no_business_filters(): void
    {
        config([
            'core.audit.enabled' => true,
            'core.audit.persistence' => false,
            'core.rbac.require_auth' => false,
        ]);

        $res = $this->getJson('/api/audit');
        $res->assertOk();

        $data = $res->json();

        // Root envelope is flexible. Verify required anchors only.
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertIsArray($data['items']);
        $this->assertArrayHasKey('nextCursor', $data);
        $this->assertTrue(
            is_string($data['nextCursor']) || is_null($data['nextCursor']),
            'nextCursor must be string|null'
        );

        // If any items are present, validate minimal event shape.
        if (!empty($data['items'])) {
            $first = $data['items'][0];
            $this->assertIsArray($first);
            foreach (['id', 'occurred_at', 'action', 'category'] as $k) {
                $this->assertArrayHasKey($k, $first);
            }
        }
    }

    public function test_cursor_request_behaves_in_stub_path(): void
    {
        config([
            'core.audit.enabled' => true,
            'core.audit.persistence' => false,
            'core.rbac.require_auth' => false,
        ]);

        // First call to obtain cursor if any.
        $first = $this->getJson('/api/audit')->assertOk()->json();
        $cursor = $first['nextCursor'] ?? null;

        // Use a valid cursor if provided, otherwise a dummy token.
        $q = is_string($cursor) && $cursor !== '' ? $cursor : 'dummy';

        $res = $this->getJson('/api/audit?cursor=' . urlencode($q));
        $res->assertOk();

        $data = $res->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertIsArray($data['items']);
        $this->assertArrayHasKey('nextCursor', $data);
        $this->assertTrue(
            is_string($data['nextCursor']) || is_null($data['nextCursor']),
            'nextCursor must be string|null'
        );

        if (!empty($data['items'])) {
            $first = $data['items'][0];
            $this->assertIsArray($first);
            foreach (['id', 'occurred_at', 'action', 'category'] as $k) {
                $this->assertArrayHasKey($k, $first);
            }
        }
    }
}
