<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Http\Middleware\RbacMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardKpisMoreValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RbacMiddleware::class);
    }

    public function test_negative_values_clamp_to_min(): void
    {
        $res = $this->getJson('/dashboard/kpis?days=-5&rbac_days=-2');
        $res->assertOk();

        if ($res->json('data')) {
            $res->assertJsonPath('data.rbac_denies.window_days', 1);
            $res->assertJsonPath('data.evidence_freshness.days', 1);
        } else {
            $res->assertJsonPath('rbac_denies.window_days', 1);
            $res->assertJsonPath('evidence_freshness.days', 1);
        }
    }

    public function test_array_params_use_first_value_and_clamp(): void
    {
        $res = $this->getJson('/dashboard/kpis?days[]=4&days[]=9&rbac_days[]=8');
        $res->assertOk();

        if ($res->json('data')) {
            $res->assertJsonPath('data.rbac_denies.window_days', 8);
            $res->assertJsonPath('data.evidence_freshness.days', 4);
        } else {
            $res->assertJsonPath('rbac_denies.window_days', 8);
            $res->assertJsonPath('evidence_freshness.days', 4);
        }
    }

    public function test_float_values_fall_back_to_defaults(): void
    {
        $res = $this->getJson('/dashboard/kpis?days=3.5&rbac_days=12.7');
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
