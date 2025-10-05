<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EvidenceUploadCapabilityTest extends TestCase
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
            'core.evidence.enabled'  => true,
        ]);

        $this->seed(TestRbacSeeder::class);
    }

    private function makeAdmin(): User
    {
        /** @var User $u */
        $u = \Database\Factories\UserFactory::new()->create();
        $adminId = Role::query()->where('name', 'Admin')->value('id');
        if (is_string($adminId)) {
            $u->roles()->syncWithoutDetaching([$adminId]);
        }
        return $u;
    }

    public function test_upload_denied_when_capability_disabled_and_writes_single_deny_audit(): void
    {
        config(['core.capabilities.core.evidence.upload' => false]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $before = (int) DB::table('audit_events')->count();

        // No file needed; middleware blocks before controller validation.
        $res = $this->postJson('/evidence', []);

        $res->assertStatus(403)
            ->assertJson([
                'ok'         => false,
                'code'       => 'CAPABILITY_DISABLED',
                'capability' => 'core.evidence.upload',
            ]);

        $after = (int) DB::table('audit_events')->count();
        $this->assertSame($before + 1, $after, 'one audit event should be written');

        $this->assertDatabaseHas('audit_events', [
            'category' => 'RBAC',
            'action'   => 'rbac.deny.capability',
        ]);
    }

    public function test_upload_allowed_when_capability_enabled_for_admin(): void
    {
        config(['core.capabilities.core.evidence.upload' => true]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $file = UploadedFile::fake()->create('note.txt', 1, 'text/plain');

        $resp = $this->post('/evidence', ['file' => $file]);

        $resp->assertStatus(201)
             ->assertJson(['ok' => true])
             ->assertJsonStructure(['id','version','sha256','size','mime','name']);
    }
}

