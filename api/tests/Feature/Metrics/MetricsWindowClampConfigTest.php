<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Http\Middleware\MetricsThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetricsWindowClampConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false, // focus on clamps, not auth
            'core.metrics.cache_ttl_seconds' => 0, // avoid cache variance
            'core.metrics.throttle.enabled' => false, // avoid 429 in CI
            'core.metrics.window.min_days' => 7,
            'core.metrics.window.max_days' => 10,
        ]);

        $this->withoutMiddleware(MetricsThrottle::class);
    }

    public function test_numeric_params_clamp_to_configured_bounds(): void
    {
        // Below min clamps up
        $r1 = $this->getJson('/dashboard/kpis?auth_days=1&rbac_days=0')->assertStatus(200);
        $r1->assertJsonPath('data.auth_activity.window_days', 7);

        // Above max clamps down
        $r2 = $this->getJson('/dashboard/kpis?auth_days=999&rbac_days=999')->assertStatus(200);
        $r2->assertJsonPath('data.auth_activity.window_days', 10);
    }

    public function test_future_range_clamps_rbac_days_to_configured_max(): void
    {
        // Large span -> rbac_days should clamp to max_days = 10
        $q = http_build_query([
            'from' => '2024-01-01',
            'to' => '2024-03-15',
            'tz' => 'UTC',
            'granularity' => 'day',
        ]);

        $r = $this->getJson('/dashboard/kpis?'.$q)->assertStatus(200);
        $r->assertJsonPath('meta.window.auth_days', 10);
    }
}
