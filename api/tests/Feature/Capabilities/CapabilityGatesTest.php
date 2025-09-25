<?php

declare(strict_types=1);

namespace Tests\Feature\Capabilities;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class CapabilityGatesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

        /** @var User $u */
        $u = \Database\Factories\UserFactory::new()->create();
        $adminRoleId = Role::query()->where('name', 'Admin')->value('id');
        if (is_string($adminRoleId)) {
            $u->roles()->syncWithoutDetaching([$adminRoleId]);
        }
        $this->admin = $u;
    }

    public function test_audit_export_denied_when_capability_disabled(): void
    {
        // Disable capability
        config(['core.capabilities.core.audit.export' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/audit/export.csv')
            ->assertStatus(403)
            ->assertJson([
                'ok'         => false,
                'code'       => 'CAPABILITY_DISABLED',
                'capability' => 'core.audit.export',
            ]);
    }

    public function test_evidence_upload_denied_when_capability_disabled(): void
    {
        // Disable capability
        config(['core.capabilities.core.evidence.upload' => false]);

        $file = UploadedFile::fake()->create('note.txt', 1, 'text/plain');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/evidence', ['file' => $file])
            ->assertStatus(403)
            ->assertJson([
                'ok'         => false,
                'code'       => 'CAPABILITY_DISABLED',
                'capability' => 'core.evidence.upload',
            ]);
    }
}

