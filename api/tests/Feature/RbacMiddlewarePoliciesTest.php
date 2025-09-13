<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RbacMiddleware;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacMiddlewarePoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral test route that requires Admin role
        Route::middleware([RbacMiddleware::class])
            ->get('/api/test/policy', static function () {
                return response()->json(['ok' => true]);
            })
            ->defaults('roles', ['Admin']);
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

    public function test_persist_mode_requires_auth_then_allows_with_admin_role(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', true);

        // No auth -> 401
        $this->getJson('/api/test/policy')->assertStatus(401);

        // Auth with Admin -> 200
        $this->seed(RolesSeeder::class);

        $user = User::query()->create([
            'name' => 'Root',
            'email' => 'root@example.com',
            'password' => bcrypt('secret'),
        ]);

        $adminId = (string) DB::table('roles')->where('name', 'Admin')->value('id');
        $user->roles()->attach($adminId);

        Sanctum::actingAs($user);

        $this->getJson('/api/test/policy')->assertStatus(200)->assertJson(['ok' => true]);
    }

    public function test_persist_mode_forbids_when_missing_role(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.persistence', true);
        config()->set('core.rbac.require_auth', true);

        $user = User::query()->create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'password' => bcrypt('secret'),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/test/policy')->assertStatus(403);
    }
}

