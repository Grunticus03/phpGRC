<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RbacMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class RbacMiddlewarePoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral test route that requires Admin role and core.settings.manage policy
        Route::middleware([RbacMiddleware::class])
            ->get('/api/test/policy', static function () {
                return response()->json(['ok' => true]);
            })
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'core.settings.manage');
    }

    public function test_stub_mode_allows_without_auth(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'stub');
        config()->set('core.rbac.persistence', false);
        config()->set('core.rbac.require_auth', false);

        $res = $this->getJson('/api/test/policy');
        $res->assertStatus(200)->assertJson(['ok' => true]);
    }

    public function test_persist_mode_requires_auth_and_role_for_policy(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', true);

        // No auth -> 401
        $this->getJson('/api/test/policy')->assertStatus(401);

        // Auth with Admin -> 200
        /** @var User $user */
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('hasAnyRole')->with(['Admin'])->andReturn(true);

        Sanctum::actingAs($user);

        $this->getJson('/api/test/policy')->assertStatus(200)->assertJson(['ok' => true]);
    }

    public function test_persist_mode_forbids_when_missing_role(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', true);

        /** @var User $user */
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('getAuthIdentifier')->andReturn(2);
        $user->shouldReceive('hasAnyRole')->with(['Admin'])->andReturn(false);

        Sanctum::actingAs($user);

        $this->getJson('/api/test/policy')->assertStatus(403);
    }
}

