<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Http\Middleware\RbacMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardKpisFutureParamsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep RBAC enabled but allow anonymous to hit the endpoint.
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
        ]);
        // Avoid throttle flakiness in CI; defaults are OK, just ensure enabled.
        config(['core.metrics.throttle.enabled' => true]);

        // Let middleware run (we rely on require_auth=false fast-path)
        $this->withoutMiddleware([]); // explicit no-op
    }

    public function test_invalid_tz_rejected(): void
    {
        $r = $this->getJson('/dashboard/kpis?from=2025-09-01&to=2025-09-07&tz=Nope/City');
        $r->assertStatus(422)
          ->assertJsonPath('ok', false)
          ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('tz', $r->json('errors') ?? []);
    }

    public function test_invalid_granularity_rejected(): void
    {
        $r = $this->getJson('/dashboard/kpis?from=2025-01-01&to=2025-01-02&granularity=hour');
        $r->assertStatus(422)
          ->assertJsonPath('ok', false)
          ->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('granularity', $r->json('errors') ?? []);
    }

    public function test_from_after_to_rejected(): void
    {
        $r = $this->getJson('/dashboard/kpis?from=2025-09-10&to=2025-09-01&tz=UTC');
        $r->assertStatus(422)
          ->assertJsonPath('ok', false)
          ->assertJsonPath('code', 'VALIDATION_FAILED');
        $errs = $r->json('errors') ?? [];
        $this->assertArrayHasKey('from', $errs);
        $this->assertContains('AFTER_TO', $errs['from'] ?? []);
    }

    public function test_incomplete_range_is_ignored_and_returns_200(): void
    {
        $a = $this->getJson('/dashboard/kpis?from=2025-09-01'); // only from
        $a->assertOk()->assertJsonPath('ok', true);

        $b = $this->getJson('/dashboard/kpis?to=2025-09-07');   // only to
        $b->assertOk()->assertJsonPath('ok', true);
    }

    public function test_large_window_clamped_to_max_meta_reflects_rbac_days(): void
    {
        // 900-day span clamps to 365
        $r = $this->getJson('/dashboard/kpis?from=2022-01-01&to=2024-06-30&tz=UTC&granularity=day');
        $r->assertOk()->assertJsonPath('ok', true);

        $rbacDays = (int) ($r->json('meta.window.rbac_days') ?? -1);
        $this->assertGreaterThanOrEqual(1, $rbacDays);
        $this->assertLessThanOrEqual(365, $rbacDays);
        $this->assertSame(365, $rbacDays);
    }

    public function test_alias_rejects_same_invalid_params(): void
    {
        $r = $this->getJson('/metrics/dashboard?from=not-a-date&to=2025-01-01');
        $r->assertStatus(422)
          ->assertJsonPath('ok', false)
          ->assertJsonPath('code', 'VALIDATION_FAILED');
    }
}

