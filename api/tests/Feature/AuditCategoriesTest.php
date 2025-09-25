<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
