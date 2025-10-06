<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RbacMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RbacPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__t/rbac-mw', function (Request $request) {
            return response()->json([
                'rbac_enabled' => $request->attributes->get('rbac_enabled'),
            ]);
        })->middleware(RbacMiddleware::class);
    }

    public function test_middleware_tags_enabled_flag(): void
    {
        config(['core.rbac.enabled' => true]);
        $this->get('/__t/rbac-mw')->assertOk()->assertJson(['rbac_enabled' => true]);

        config(['core.rbac.enabled' => false]);
        $this->get('/__t/rbac-mw')->assertOk()->assertJson(['rbac_enabled' => false]);
    }
}
