<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_by_category_and_time_range(): void
    {
        $t0 = CarbonImmutable::parse('2025-09-01T00:00:00Z');
        $t1 = $t0->addDay();
        $t2 = $t0->addDays(2);

        AuditEvent::query()->create([
            'id' => '01AAAAAAAAAAAAAAAAAAAAAAA1',
            'occurred_at' => $t1,
            'actor_id' => null,
            'action' => 'rbac.role.created',
            'category' => 'RBAC',
            'entity_type' => 'role',
            'entity_id' => 'role_admin',
            'ip' => null,
            'ua' => null,
            'meta' => ['name' => 'Admin'],
            'created_at' => $t1,
        ]);

        AuditEvent::query()->create([
            'id' => '01BBBBBBBBBBBBBBBBBBBBBBB2',
            'occurred_at' => $t2,
            'actor_id' => null,
            'action' => 'auth.login',
            'category' => 'AUTH',
            'entity_type' => 'user',
            'entity_id' => '1',
            'ip' => '127.0.0.1',
            'ua' => 'phpunit',
            'meta' => null,
            'created_at' => $t2,
        ]);

        // category=RBAC only
        $res = $this->getJson('/audit?category=RBAC&order=asc&limit=10');
        $res->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.category', 'RBAC')
            ->assertJsonPath('items.0.action', 'rbac.role.created');

        // occurred_from filters out first record
        $res2 = $this->getJson('/audit?occurred_from='.urlencode($t2->toIso8601String()));
        $res2->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.category', 'AUTH');
    }

    public function test_cursor_paging_contract(): void
    {
        // No rows => stub-only dataset should be returned with paging fields present
        $first = $this->getJson('/audit?limit=1&order=desc');
        $first->assertStatus(200)->assertJsonPath('ok', true);
        $cursor = $first->json('nextCursor');
        $this->assertIsString($cursor);

        $second = $this->getJson('/audit?order=desc&cursor='.urlencode($cursor));
        $second->assertStatus(200)->assertJsonPath('ok', true);
    }
}
