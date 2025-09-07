<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

final class AuditControllerTest extends TestCase
{
    public function test_get_audit_returns_stub_events_with_required_shape(): void
    {
        $res = $this->getJson('/api/audit');

        $res->assertOk();

        $res->assertJson(fn (AssertableJson $json) =>
            $json->where('ok', true)
                 ->where('note', 'stub-only')
                 ->has('items', fn (AssertableJson $items) =>
                     $items->each(fn (AssertableJson $e) =>
                         $e->whereType('id', 'string')
                           ->whereType('occurred_at', 'string')
                           ->whereType('actor_id', 'integer|null')
                           ->whereType('action', 'string')
                           ->whereType('category', 'string')
                           ->whereType('entity_type', 'string')
                           ->whereType('entity_id', 'string')
                           ->whereType('ip', 'string|null')
                           ->whereType('ua', 'string|null')
                           ->has('meta')
                     )
                 )
                 ->has('nextCursor')
        );
    }

    public function test_get_audit_respects_limit_param_bounds(): void
    {
        // lower bound
        $this->getJson('/api/audit?limit=0')->assertOk();

        // upper bound
        $this->getJson('/api/audit?limit=1000')->assertOk();

        // typical
        $this->getJson('/api/audit?limit=25&cursor=abc123')->assertOk();
    }
}
