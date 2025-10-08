<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\RbacMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EvidencePurgeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(RbacMiddleware::class);

        config([
            'queue.default' => 'sync',
            'core.audit.enabled' => true,
        ]);
    }

    public function test_requires_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/admin/evidence/purge', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['confirm']]);
    }

    public function test_purge_deletes_all_evidence_and_writes_audit_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $now = now();
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $rows[] = [
                'id' => 'ev_'.(string) Str::ulid(),
                'owner_id' => $user->id,
                'filename' => "fixture_{$i}.txt",
                'mime' => 'text/plain',
                'size_bytes' => 1,
                'sha256' => hash('sha256', (string) $i),
                'version' => 1,
                'bytes' => 'A',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('evidence')->insert($rows);

        $this->assertDatabaseCount('evidence', 3);

        $this->postJson('/admin/evidence/purge', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('deleted', 3)
            ->assertJsonPath('ok', true);

        $this->assertDatabaseCount('evidence', 0);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'evidence.purged',
            'entity_id' => 'all',
        ]);
    }
}
