<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditExportCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled'      => true,
            'core.rbac.mode'         => 'persist',
            'core.rbac.persistence'  => true,
            'core.rbac.require_auth' => true,
            'core.audit.enabled'     => true,
        ]);

        $this->seed(TestRbacSeeder::class);
    }

    private function makeAuditor(): User
    {
        /** @var User $u */
        $u = \Database\Factories\UserFactory::new()->create();
        $auditorId = Role::query()->where('name', 'Auditor')->value('id');
        if (is_string($auditorId)) {
            $u->roles()->syncWithoutDetaching([$auditorId]);
        }
        return $u;
    }

    public function test_export_denied_when_capability_disabled_and_writes_single_deny_audit(): void
    {
        config(['core.capabilities.core.audit.export' => false]);

        $auditor = $this->makeAuditor();
        $this->actingAs($auditor, 'sanctum');

        $before = (int) DB::table('audit_events')->count();

        $res = $this->getJson('/audit/export.csv');

        $res->assertStatus(403)
            ->assertJson([
                'ok'         => false,
                'code'       => 'CAPABILITY_DISABLED',
                'capability' => 'core.audit.export',
            ]);

        $after = (int) DB::table('audit_events')->count();
        $this->assertSame($before + 1, $after, 'one audit event should be written');

        $this->assertDatabaseHas('audit_events', [
            'category' => 'RBAC',
            'action'   => 'rbac.deny.capability',
        ]);
    }

    public function test_export_allowed_when_capability_enabled_for_auditor(): void
    {
        config(['core.capabilities.core.audit.export' => true]);

        $auditor = $this->makeAuditor();
        $this->actingAs($auditor, 'sanctum');

        $resp = $this->get('/audit/export.csv');

        $resp->assertStatus(200);
        $ctype = (string) $resp->headers->get('Content-Type', '');
        $this->assertNotSame('', $ctype);
        $this->assertStringStartsWith('text/csv', $ctype);
    }
}

