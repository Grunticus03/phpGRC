<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

final class AuditControllerTest extends TestCase
{
    public function test_get_audit_returns_stub_events_with_required_shape(): void
    {
        $res = $this->getJson('/audit');

        $res->assertOk();

        $res->assertJson(fn (AssertableJson $json) =>
            $json->where('ok', true)
                 ->where('note', 'stub-only')
                 ->has('items', fn (AssertableJson $items) =>
                     $items->each(fn (AssertableJson $e) =>
                         $e->whereType('id', 'string')
                           ->whereType('occurred_at', 'string')
                           ->whereType('actor_id', 'integer|null')
                           ->whereType('actor_label', 'string|null')
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
                 ->etc()
        );
    }

    public function test_get_audit_respects_limit_param_bounds(): void
    {
        // 0 and >100 are invalid → 422
        $this->getJson('/audit?limit=0')->assertStatus(422)->assertJsonStructure(['message','errors']);
        $this->getJson('/audit?limit=1000')->assertStatus(422)->assertJsonStructure(['message','errors']);

        // typical valid request
        $this->getJson('/audit?limit=25&cursor=abc123')->assertOk();
    }
}
