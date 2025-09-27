<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Http\Controllers\Rbac\UserSearchController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UserSearchAuthEnforcementTest extends TestCase
{
    private function stubNoTables(): void
    {
        Schema::shouldReceive('hasTable')->with('core_settings')->zeroOrMoreTimes()->andReturn(false);
        Schema::shouldReceive('hasTable')->with('users')->zeroOrMoreTimes()->andReturn(false);
    }

    private function registerSearchRoute(bool $requireAuth): void
    {
        // Reset routes for isolation.
        Route::middleware('api')->group(function () use ($requireAuth): void {
            $route = Route::get('/api/rbac/users/search', [UserSearchController::class, 'index']);
            if ($requireAuth) {
                $route->middleware('auth:sanctum');
            }
        });
    }

    public function test_requires_auth_when_flag_enabled(): void
    {
        $this->stubNoTables();
        config(['core.rbac.require_auth' => true]);
        $this->registerSearchRoute(true);

        $resp = $this->getJson('/api/rbac/users/search?q=alpha');
        $resp->assertStatus(401);
    }

    public function test_allows_guest_when_flag_disabled(): void
    {
        $this->stubNoTables();
        config(['core.rbac.require_auth' => false]);
        $this->registerSearchRoute(false);

        $resp = $this->getJson('/api/rbac/users/search?q=alpha');

        $resp->assertStatus(200)
             ->assertJson([
                 'ok' => true,
                 'data' => [],
                 'meta' => [
                     'page' => 1,
                     'per_page' => 50,
                     'total' => 0,
                     'total_pages' => 0,
                 ],
             ]);
    }
}

