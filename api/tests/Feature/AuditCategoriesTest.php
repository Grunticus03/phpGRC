<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Support\Audit\AuditCategories;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.rbac.enabled', false);
        config()->set('core.rbac.require_auth', false);
        config()->set('core.audit.enabled', true);
    }

    public function test_categories_endpoint_returns_list(): void
    {
        $res = $this->getJson('/audit/categories');

        $res->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'categories'])
            ->assertJson(fn ($json) =>
                $json->whereType('categories', 'array')
                     ->where('ok', true)
                     ->etc() // allow additional documented fields
            );
    }

    public function test_only_existing_categories_are_listed(): void
    {
        $now = CarbonImmutable::now('UTC');

        AuditEvent::query()->create([
            'id'          => (string) Str::ulid(),
            'occurred_at' => $now,
            'actor_id'    => null,
            'action'      => 'settings.update',
            'category'    => 'config',
            'entity_type' => 'core.settings',
            'entity_id'   => 'core',
            'ip'          => null,
            'ua'          => null,
            'meta'        => [],
            'created_at'  => $now,
        ]);

        AuditEvent::query()->create([
            'id'          => (string) Str::ulid(),
            'occurred_at' => $now->addMinute(),
            'actor_id'    => null,
            'action'      => 'rbac.role.created',
            'category'    => AuditCategories::RBAC,
            'entity_type' => 'core.rbac.role',
            'entity_id'   => 'role_admin',
            'ip'          => null,
            'ua'          => null,
            'meta'        => [],
            'created_at'  => $now->addMinute(),
        ]);

        $res = $this->getJson('/audit/categories');

        $res->assertOk();
        $payload = $res->json('categories');
        static::assertSame([AuditCategories::RBAC, AuditCategories::SETTINGS], $payload);
    }

}
