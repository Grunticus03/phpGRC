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
        $res = $this->getJson('/dashboard/kpis?auth_days=-5&rbac_days=-2');
        $res->assertOk();

        if ($res->json('data')) {
            $res->assertJsonPath('data.auth_activity.window_days', 7);
        } else {
            $res->assertJsonPath('auth_activity.window_days', 7);
        }
    }

    public function test_array_params_use_first_value_and_clamp(): void
    {
        $res = $this->getJson('/dashboard/kpis?auth_days[]=8&auth_days[]=14');
        $res->assertOk();

        if ($res->json('data')) {
            $res->assertJsonPath('data.auth_activity.window_days', 8);
        } else {
            $res->assertJsonPath('auth_activity.window_days', 8);
        }
    }

    public function test_float_values_fall_back_to_defaults(): void
    {
        $res = $this->getJson('/dashboard/kpis?auth_days=12.7');
        $res->assertOk();

        if ($res->json('data')) {
            $res->assertJsonPath('data.auth_activity.window_days', 7);
        } else {
            $res->assertJsonPath('auth_activity.window_days', 7);
        }
    }
}
