<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable audit. Disable RBAC to avoid role gating in list tests.
        config()->set('core.audit.enabled', true);
        config()->set('core.rbac.enabled', false);
        config()->set('core.rbac.require_auth', false);
    }

    public function test_stub_path_first_page_defaults_to_two_items(): void
    {
        // No rows in DB and no business filters -> stub path
        $res = $this->getJson('/audit');

        $res->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('note', 'stub-only')
            ->assertJsonCount(2, 'items');
    }

    public function test_filters_category_and_order_with_db_rows(): void
    {
        // Seed three events: two RBAC, one AUTH
        $t0 = Carbon::parse('2025-01-01T00:00:00Z');
        $t1 = Carbon::parse('2025-01-01T00:00:05Z');
        $t2 = Carbon::parse('2025-01-01T00:00:10Z');

        $this->insertEvent([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $t1,
            'category'    => 'RBAC',
            'action'      => 'rbac.user_role.attached',
            'entity_type' => 'user',
            'entity_id'   => '1',
        ]);

        $this->insertEvent([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $t0,
            'category'    => 'AUTH',
            'action'      => 'auth.login',
            'entity_type' => 'user',
            'entity_id'   => '2',
        ]);

        $this->insertEvent([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $t2,
            'category'    => 'RBAC',
            'action'      => 'rbac.user_role.detached',
            'entity_type' => 'user',
            'entity_id'   => '3',
        ]);

        $res = $this->getJson('/audit?category=RBAC&order=asc&limit=10');

        $res->assertStatus(200)->assertJsonPath('ok', true);

        $json = $res->json();
        $this->assertIsArray($json['items']);
        $this->assertCount(2, $json['items']);
        $this->assertSame('RBAC', $json['items'][0]['category']);
        $this->assertSame('RBAC', $json['items'][1]['category']);

        // Ascending by occurred_at then id
        $a = Carbon::parse($json['items'][0]['occurred_at']);
        $b = Carbon::parse($json['items'][1]['occurred_at']);
        $this->assertTrue($a->lessThanOrEqualTo($b));
    }

    public function test_cursor_pagination_moves_forward(): void
    {
        // Seed two events
        $t0 = Carbon::parse('2025-01-02T00:00:00Z');
        $t1 = Carbon::parse('2025-01-02T00:00:01Z');

        $firstId = Str::ulid()->toBase32();
        $secondId = Str::ulid()->toBase32();

        $this->insertEvent([
            'id'          => $firstId,
            'occurred_at' => $t1,
            'category'    => 'SYSTEM',
            'action'      => 'stub.a',
            'entity_type' => 'sys',
            'entity_id'   => 'A',
        ]);

        $this->insertEvent([
            'id'          => $secondId,
            'occurred_at' => $t0,
            'category'    => 'SYSTEM',
            'action'      => 'stub.b',
            'entity_type' => 'sys',
            'entity_id'   => 'B',
        ]);

        // First page, limit=1, descending (newest first)
        $r1 = $this->getJson('/audit?limit=1&order=desc');
        $r1->assertStatus(200)->assertJsonPath('ok', true)->assertJsonCount(1, 'items');
        $page1 = $r1->json();
        $this->assertNotEmpty($page1['nextCursor'] ?? null);

        $firstItemId = $page1['items'][0]['id'];

        // Second page using cursor should yield a different id if available
        $cursor = $page1['nextCursor'];
        $r2 = $this->getJson('/audit?limit=1&order=desc&cursor=' . urlencode($cursor));
        $r2->assertStatus(200)->assertJsonPath('ok', true)->assertJsonCount(1, 'items');

        $page2 = $r2->json();
        $this->assertNotSame($firstItemId, $page2['items'][0]['id']);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function insertEvent(array $overrides = []): AuditEvent
    {
        $now = Carbon::now('UTC');

        $data = array_merge([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $now,
            'actor_id'    => null,
            'action'      => 'stub.event',
            'category'    => 'SYSTEM',
            'entity_type' => 'stub',
            'entity_id'   => '0',
            'ip'          => null,
            'ua'          => null,
            'meta'        => null,
            'created_at'  => $now,
        ], $overrides);

        /** @var AuditEvent $ev */
        $ev = AuditEvent::query()->create($data);
        return $ev;
    }
}
