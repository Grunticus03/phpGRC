<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RbacAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_create_logs_audit_event(): void
    {
        config([
            'core.rbac.enabled'      => true,
            'core.rbac.require_auth' => false,
            'core.rbac.persistence'  => true,
            'core.rbac.mode'         => 'persist',
            'core.audit.enabled'     => true,
        ]);

        $this->postJson('/rbac/roles', ['name' => 'Compliance-Lead'])->assertStatus(201);

        $row = DB::table('audit_events')->where('action', 'rbac.role.created')->first();
        $this->assertNotNull($row);

        $meta = json_decode((string) $row->meta, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Compliance-Lead', $meta['name'] ?? null);
        $this->assertSame('compliance-lead', $meta['name_normalized'] ?? null);
    }
}
