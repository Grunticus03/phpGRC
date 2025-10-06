<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Http\Middleware\RbacMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardKpisValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Focus this suite on parameter validation/clamping, not RBAC.
     * RBAC behavior is covered elsewhere.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RbacMiddleware::class);

        // Ensure deterministic defaults used by assertions below.
        config([
            'core.metrics.evidence_freshness.days' => 30,
            'core.metrics.rbac_denies.window_days' => 7,
        ]);
    }

    public function test_clamps_low_values_to_min(): void
    {
        $res = $this->getJson('/dashboard/kpis?days=0&rbac_days=0');
        $res->assertOk();

        if ($res->json('data')) {
            $res->assertJsonPath('data.rbac_denies.window_days', 1);
            $res->assertJsonPath('data.evidence_freshness.days', 1);
        } else {
            $res->assertJsonPath('rbac_denies.window_days', 1);
            $res->assertJsonPath('evidence_freshness.days', 1);
        }
    }

    public function test_clamps_high_values_to_max(): void
    {
        $res = $this->getJson('/dashboard/kpis?days=999&rbac_days=999');
        $res->assertOk();

        if ($res->json('data')) {
            $res->assertJsonPath('data.rbac_denies.window_days', 365);
            $res->assertJsonPath('data.evidence_freshness.days', 365);
        } else {
            $res->assertJsonPath('rbac_denies.window_days', 365);
            $res->assertJsonPath('evidence_freshness.days', 365);
        }
    }

    public function test_non_numeric_values_fall_back_to_defaults(): void
    {
        $res = $this->getJson('/dashboard/kpis?days=foo&rbac_days=bar');
        $res->assertOk();

        if ($res->json('data')) {
            $res->assertJsonPath('data.rbac_denies.window_days', 7);
            $res->assertJsonPath('data.evidence_freshness.days', 30);
        } else {
            $res->assertJsonPath('rbac_denies.window_days', 7);
            $res->assertJsonPath('evidence_freshness.days', 30);
        }
    }

    /** FUTURE PARAMS */
    public function test_invalid_timezone_rejected(): void
    {
        $r = $this->getJson('/dashboard/kpis?tz=Not/AZone');
        $r->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['tz']]);
    }

    public function test_invalid_from_format_rejected(): void
    {
        $r = $this->getJson('/dashboard/kpis?from=invalid&to=2025-09-01');
        $r->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['from']]);
    }

    public function test_invalid_to_format_rejected(): void
    {
        $r = $this->getJson('/dashboard/kpis?from=2025-08-01&to=x');
        $r->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['to']]);
    }

    public function test_from_after_to_rejected(): void
    {
        $r = $this->getJson('/dashboard/kpis?from=2025-09-10&to=2025-09-01');
        $r->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['from']]);
    }

    public function test_unsupported_granularity_rejected(): void
    {
        $r = $this->getJson('/dashboard/kpis?granularity=hour');
        $r->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['granularity']]);
    }

    public function test_valid_from_to_tz_and_granularity_day_are_accepted_and_affect_window(): void
    {
        // Window spans 10 days inclusive -> rbac_days should resolve to 10 (capped/clamped if needed)
        $r = $this->getJson('/dashboard/kpis?from=2025-09-01&to=2025-09-10&tz=UTC&granularity=day');
        $r->assertOk()
            ->assertJsonPath('ok', true);

        // window meta is optional, but if present assert the numbers we rely on
        $rbacDays = (int) ($r->json('meta.window.rbac_days') ?? $r->json('data.rbac_denies.window_days'));
        $this->assertSame(10, $rbacDays);
    }

    public function test_from_to_exceeding_max_is_clamped_to_365(): void
    {
        $r = $this->getJson('/dashboard/kpis?from=2024-01-01&to=2025-12-31&tz=UTC&granularity=day');
        $r->assertOk()
            ->assertJsonPath('ok', true);

        $rbacDays = (int) ($r->json('meta.window.rbac_days') ?? $r->json('data.rbac_denies.window_days'));
        $this->assertSame(365, $rbacDays);
    }
}
