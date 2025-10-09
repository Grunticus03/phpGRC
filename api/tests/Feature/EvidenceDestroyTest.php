<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EvidenceDestroyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.rbac.enabled', false);

        Gate::define('core.evidence.manage', static fn (User $u): bool => true);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
    }

    public function test_destroy_deletes_record_and_audits(): void
    {
        $evidence = Evidence::factory()->create();
        $id = $evidence->id;

        $res = $this->deleteJson('/evidence/'.$id);
        $res->assertStatus(200)->assertJsonPath('ok', true)->assertJsonPath('id', $id);

        $this->assertDatabaseMissing('evidence', ['id' => $id]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'evidence.deleted',
            'entity_id' => $id,
        ]);
    }

    public function test_destroy_returns_not_found_when_missing(): void
    {
        $res = $this->deleteJson('/evidence/ev_missing');
        $res->assertStatus(404)->assertJsonPath('code', 'EVIDENCE_NOT_FOUND');
    }
}
