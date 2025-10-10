<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Http\Middleware\RbacMiddleware;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RbacMiddlewareRoleDenyAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([RbacMiddleware::class])
            ->get('/test/role-gate', static fn () => response()->json(['ok' => true]))
            ->defaults('roles', ['Admin', 'Auditor']);
    }

    public function test_role_mismatch_emits_single_rbac_deny_role_mismatch(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
        ]);

        $user = User::query()->create([
            'name' => 'No Roles',
            'email' => 'noroles@example.test',
            'password' => bcrypt('x'),
        ]);

        // Route requires Admin or Auditor; user has none -> 403
        $res = $this->actingAs($user, 'sanctum')->getJson('/test/role-gate');
        $res->assertStatus(403)->assertJson(['ok' => false, 'code' => 'FORBIDDEN']);

        $rows = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.deny.role_mismatch')
            ->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('route', $row->entity_type);
        $this->assertNotSame('', (string) $row->entity_id);
        $this->assertIsArray($row->meta);
        $this->assertSame('role', $row->meta['reason'] ?? null);
        // meta includes required_roles and rbac_mode
        $this->assertArrayHasKey('required_roles', $row->meta);
        $this->assertSame('persist', $row->meta['rbac_mode'] ?? null);
        $this->assertNotSame('', (string) ($row->meta['request_id'] ?? ''));
    }
}
