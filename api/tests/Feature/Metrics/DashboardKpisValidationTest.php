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
     * RBAC behavior is covered in DashboardKpisAuthTest.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RbacMiddleware::class);
    }

    public function test_clamps_low_values_to_min(): void
    {
        // days and rbac_days below 1 should clamp to 1
        $res = $this->getJson('/dashboard/kpis?days=0&rbac_days=0');

        $res->assertOk();

        // Accept either {ok,data:{...}} or direct object
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
        // days and rbac_days above 365 should clamp to 365
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
        // Non-numeric inputs should be ignored and defaults applied (7, 30).
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
}
