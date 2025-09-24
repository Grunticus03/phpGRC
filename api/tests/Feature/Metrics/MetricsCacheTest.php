<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Http\Middleware\RbacMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetricsCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Focus on cache behavior, not RBAC.
        $this->withoutMiddleware(RbacMiddleware::class);
    }

    public function test_cache_disabled_when_ttl_zero(): void
    {
        config(['core.metrics.cache_ttl_seconds' => 0]);

        $r1 = $this->getJson('/api/dashboard/kpis');
        $r1->assertOk();
        $r1->assertJsonPath('meta.cache.ttl', 0);
        $this->assertFalse((bool) $r1->json('meta.cache.hit'));

        $r2 = $this->getJson('/api/dashboard/kpis');
        $r2->assertOk();
        $r2->assertJsonPath('meta.cache.ttl', 0);
        $this->assertFalse((bool) $r2->json('meta.cache.hit'));
    }

    public function test_cache_hits_and_varies_by_params(): void
    {
        config(['core.metrics.cache_ttl_seconds' => 60]);

        // First call with defaults -> miss
        $a = $this->getJson('/api/dashboard/kpis');
        $a->assertOk();
        $a->assertJsonPath('meta.cache.ttl', 60);
        $this->assertFalse((bool) $a->json('meta.cache.hit'));

        // Same params -> hit
        $b = $this->getJson('/api/dashboard/kpis');
        $b->assertOk();
        $b->assertJsonPath('meta.cache.ttl', 60);
        $this->assertTrue((bool) $b->json('meta.cache.hit'));

        // Change window param -> miss (different cache key)
        $c = $this->getJson('/api/dashboard/kpis?days=31&rbac_days=7');
        $c->assertOk();
        $c->assertJsonPath('meta.cache.ttl', 60);
        $this->assertFalse((bool) $c->json('meta.cache.hit'));
    }
}

