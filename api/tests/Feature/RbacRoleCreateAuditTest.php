<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RbacRoleCreateAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_create_emits_rbac_role_created_audit(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => true,
            'core.rbac.mode' => 'persist',
            'core.audit.enabled' => true,
        ]);

        $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead'])->assertStatus(201);

        $row = DB::table('audit_events')->where('action', 'rbac.role.created')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        $meta = json_decode((string) $row->meta, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('compliance_lead', $meta['name'] ?? null);
        $this->assertSame('compliance_lead', $meta['name_normalized'] ?? null);
        $this->assertSame('compliance_lead created by System', $meta['message'] ?? null);
        $this->assertSame('Compliance Lead', $meta['role_label'] ?? null);
    }

    public function test_role_update_emits_rbac_role_updated_audit(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => true,
            'core.rbac.mode' => 'persist',
            'core.audit.enabled' => true,
        ]);

        $this->seed(RolesSeeder::class);

        $this->patchJson('/rbac/roles/role_admin', ['name' => 'Admin_Primary'])->assertStatus(200);

        $row = DB::table('audit_events')->where('action', 'rbac.role.updated')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        $meta = json_decode((string) $row->meta, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('admin_primary renamed from Admin by System', $meta['message'] ?? null);
        $this->assertSame('admin_primary', $meta['name'] ?? null);
        $this->assertSame('Admin', $meta['name_previous'] ?? null);
    }

    public function test_role_delete_emits_rbac_role_deleted_audit(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence' => true,
            'core.rbac.mode' => 'persist',
            'core.audit.enabled' => true,
        ]);

        Role::query()->create(['id' => 'role_temp', 'name' => 'temp']);

        $this->deleteJson('/rbac/roles/role_temp')->assertStatus(200);

        $row = DB::table('audit_events')->where('action', 'rbac.role.deleted')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        $meta = json_decode((string) $row->meta, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('temp deleted by System', $meta['message'] ?? null);
        $this->assertSame('temp', $meta['name'] ?? null);
    }
}
