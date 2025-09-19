<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

final class AuditStubPathTest extends TestCase
{
    public function test_index_returns_stub_only_when_persistence_disabled_and_no_business_filters(): void
    {
        // Ensure audit feature is enabled and requests are anonymous-passable.
        config(['core.audit.enabled' => true]);
        config(['core.rbac.require_auth' => false]);

        // Simulate disabled persistence by removing the audit table.
        Schema::dropIfExists('audit_events');

        $res = $this->getJson('/api/audit');

        $res->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('note', 'stub-only')
                ->whereType('_categories', 'array')
                ->whereType('_retention_days', 'integer')
                ->where('filters.order', 'desc')
                ->where('filters.limit', 2)
                ->where('filters.cursor', null)
                ->has('items', 2)
                ->has('items.0', fn (AssertableJson $j) => $j->hasAll('id', 'occurred_at', 'action', 'category'))
                ->has('nextCursor')
            );
    }

    public function test_cursor_request_defaults_to_one_item_in_stub_path(): void
    {
        config(['core.audit.enabled' => true]);
        config(['core.rbac.require_auth' => false]);

        Schema::dropIfExists('audit_events');

        $first = $this->getJson('/api/audit')->assertOk();
        $nextCursor = $first->json('nextCursor');
        $this->assertIsString($nextCursor);

        $second = $this->getJson('/api/audit?cursor=' . urlencode($nextCursor));

        $second->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('note', 'stub-only')
                ->has('items', 1)
                ->has('nextCursor')
            );
    }
}
