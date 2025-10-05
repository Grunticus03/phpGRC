<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Http\Middleware\MetricsThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetricsAliasParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Allow anonymous so we focus on payload equality
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            // Disable cache so 'hit' does not diverge call-to-call
            'core.metrics.cache_ttl_seconds' => 0,
            // Disable throttle to avoid 429s in CI
            'core.metrics.throttle.enabled' => false,
        ]);

        $this->withoutMiddleware(MetricsThrottle::class);
    }

    public function test_defaults_payload_is_identical_between_primary_and_alias(): void
    {
        $a = $this->getJson('/dashboard/kpis')->assertStatus(200)->json();
        $b = $this->getJson('/metrics/dashboard')->assertStatus(200)->json();

        $this->assertSame($this->stripVolatile($a), $this->stripVolatile($b));
    }

    public function test_parametrized_payload_is_identical_between_primary_and_alias(): void
    {
        $qs = http_build_query([
            'days' => 45,
            'rbac_days' => 12,
            'from' => '2024-01-01',
            'to' => '2024-01-07',
            'tz' => 'UTC',
            'granularity' => 'day',
        ]);

        $a = $this->getJson('/dashboard/kpis?' . $qs)->assertStatus(200)->json();
        $b = $this->getJson('/metrics/dashboard?' . $qs)->assertStatus(200)->json();

        $this->assertSame($this->stripVolatile($a), $this->stripVolatile($b));
    }

    /**
     * Remove keys that are expected to vary across requests.
     *
     * @param mixed $json
     * @return mixed
     */
    private function stripVolatile($json)
    {
        if (!is_array($json)) {
            return $json;
        }

        // Shape may be either {ok,data,meta} or direct data; normalize both
        if (isset($json['meta']) && is_array($json['meta'])) {
            unset($json['meta']['generated_at']); // timestamp
        }

        return $json;
    }
}

