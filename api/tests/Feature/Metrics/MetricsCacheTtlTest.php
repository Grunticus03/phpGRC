<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Http\Middleware\MetricsThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MetricsCacheTtlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false, // allow anonymous for simplicity
            'core.metrics.cache_ttl_seconds' => 2,
            'core.metrics.rbac_denies.window_days' => 7,
            // Disable throttle to avoid 429s in tight CI loops
            'core.metrics.throttle.enabled' => false,
        ]);

        // Extra guard in case middleware ignores the flag
        $this->withoutMiddleware(MetricsThrottle::class);

        Cache::store('array')->flush();
    }

    public function test_meta_reports_ttl_and_hit_with_cache_enabled(): void
    {
        $r1 = $this->getJson('/dashboard/kpis')->assertStatus(200);
        $r1->assertJsonPath('meta.cache.ttl', 2);
        $r1->assertJsonPath('meta.cache.hit', false);

        $r2 = $this->getJson('/dashboard/kpis')->assertStatus(200);
        $r2->assertJsonPath('meta.cache.ttl', 2);
        $r2->assertJsonPath('meta.cache.hit', true);
    }

    public function test_cache_disabled_reports_ttl_zero_and_never_hits(): void
    {
        Cache::store('array')->flush();
        config(['core.metrics.cache_ttl_seconds' => 0]);

        $a = $this->getJson('/dashboard/kpis')->assertStatus(200);
        $a->assertJsonPath('meta.cache.ttl', 0);
        $a->assertJsonPath('meta.cache.hit', false);

        $b = $this->getJson('/dashboard/kpis')->assertStatus(200);
        $b->assertJsonPath('meta.cache.ttl', 0);
        $b->assertJsonPath('meta.cache.hit', false);
    }
}
