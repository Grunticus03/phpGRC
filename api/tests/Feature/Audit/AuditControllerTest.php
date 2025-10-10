<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

final class AuditControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_audit_returns_stub_events_with_required_shape(): void
    {
        $res = $this->getJson('/audit');

        $res->assertOk();

        $res->assertJson(fn (AssertableJson $json) => $json->where('ok', true)
            ->where('note', 'stub-only')
            ->has('items', fn (AssertableJson $items) => $items->each(fn (AssertableJson $e) => $e->whereType('id', 'string')
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
        $this->getJson('/audit?limit=0')->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $this->getJson('/audit?limit=1000')->assertStatus(422)->assertJsonStructure(['message', 'errors']);

        // typical valid request
        $this->getJson('/audit?limit=25&cursor=abc123')->assertOk();
    }

    public function test_settings_filter_matches_legacy_config_category(): void
    {
        $now = CarbonImmutable::now('UTC');

        AuditEvent::query()->create([
            'id' => (string) Str::ulid(),
            'occurred_at' => $now,
            'actor_id' => null,
            'action' => 'setting.modified',
            'category' => 'config',
            'entity_type' => 'core.settings',
            'entity_id' => 'core',
            'ip' => '127.0.0.1',
            'ua' => 'phpunit',
            'meta' => [],
            'created_at' => $now,
        ]);

        $response = $this->getJson('/audit?category=SETTINGS&limit=10');

        $response->assertOk();
        $json = $response->json();
        self::assertIsArray($json);
        self::assertArrayHasKey('items', $json);
        self::assertIsArray($json['items']);
        self::assertCount(1, $json['items']);
        $event = $json['items'][0];
        self::assertSame('setting.modified', $event['action']);
        self::assertSame('config', $event['category']);
    }

    public function test_action_filter_accepts_label_matches_case_insensitively(): void
    {
        $now = CarbonImmutable::now('UTC');

        AuditEvent::query()->create([
            'id' => (string) Str::ulid(),
            'occurred_at' => $now,
            'actor_id' => 42,
            'action' => 'rbac.user_role.attached',
            'category' => 'RBAC',
            'entity_type' => 'rbac.user',
            'entity_id' => '99',
            'ip' => '10.0.0.1',
            'ua' => 'phpunit',
            'meta' => [],
            'created_at' => $now,
        ]);

        $response = $this->getJson('/audit?limit=10&action=Role%20attached');
        $response->assertOk();
        $items = $response->json('items');
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertSame('rbac.user_role.attached', $items[0]['action']);

        $responseLower = $this->getJson('/audit?limit=10&action=role%20attached');
        $responseLower->assertOk();
        $itemsLower = $responseLower->json('items');
        self::assertIsArray($itemsLower);
        self::assertCount(1, $itemsLower);
        self::assertSame('rbac.user_role.attached', $itemsLower[0]['action']);
    }

    public function test_entity_type_filter_is_case_insensitive(): void
    {
        $now = CarbonImmutable::now('UTC');

        AuditEvent::query()->create([
            'id' => (string) Str::ulid(),
            'occurred_at' => $now,
            'actor_id' => null,
            'action' => 'setting.modified',
            'category' => 'SETTINGS',
            'entity_type' => 'Core.Setting',
            'entity_id' => 'core.audit.retention_days',
            'ip' => '127.0.0.1',
            'ua' => 'phpunit',
            'meta' => [],
            'created_at' => $now,
        ]);

        $response = $this->getJson('/audit?limit=10&entity_type=core.setting');
        $response->assertOk();
        $items = $response->json('items');
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertSame('Core.Setting', $items[0]['entity_type']);
    }
}
