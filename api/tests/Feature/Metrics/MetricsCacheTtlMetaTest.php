<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetricsCacheTtlMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Allow unauthenticated access path (RBAC enabled but require_auth = false)
        config([
            'core.rbac.enabled'               => true,
            'core.rbac.mode'                  => 'stub',
            'core.rbac.persistence'           => false,
            'core.rbac.require_auth'          => false,
            'core.metrics.cache_ttl_seconds'  => 60, // enable cache
        ]);
    }

    public function test_meta_reports_ttl_and_cache_hit_on_second_call(): void
    {
        // First call -> miss
        $r1 = $this->getJson('/dashboard/kpis');
        $r1->assertOk()
           ->assertJsonPath('ok', true);

        $ttl1 = $r1->json('meta.cache.ttl');
        $hit1 = $r1->json('meta.cache.hit');
        $this->assertIsInt($ttl1);
        $this->assertSame(60, $ttl1);
        $this->assertFalse((bool) $hit1);

        // Second call -> hit
        $r2 = $this->getJson('/dashboard/kpis');
        $r2->assertOk()
           ->assertJsonPath('ok', true);

        $ttl2 = $r2->json('meta.cache.ttl');
        $hit2 = $r2->json('meta.cache.hit');
        $this->assertIsInt($ttl2);
        $this->assertSame(60, $ttl2);
        $this->assertTrue((bool) $hit2);
    }
}

