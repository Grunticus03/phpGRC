<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RbacAuditVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', false);
        $this->seed(RolesSeeder::class);
    }

    private function makeUser(string $name, string $email): User
    {
        return User::query()->create([
            'name'     => $name,
            'email'    => $email,
            'password' => bcrypt('secret'),
        ]);
    }

    private function countAuditsForUser(User $u): int
    {
        return (int) DB::table('audit_events')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->count();
    }

    public function test_attach_emits_canonical_event_with_null_actor_when_unauthenticated(): void
    {
        $u = $this->makeUser('Alice', 'alice@example.com');

        $before = $this->countAuditsForUser($u);

        $this->postJson("/rbac/users/{$u->id}/roles/Auditor")
            ->assertStatus(200);

        $rows = DB::table('audit_events')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->orderBy('occurred_at', 'desc')
            ->limit(1)
            ->get()
            ->map(fn ($r) => ['action' => $r->action, 'category' => $r->category, 'actor_id' => $r->actor_id])
            ->all();

        $this->assertCount(1, $rows);
        $actions = array_column($rows, 'action');
        $this->assertContains('rbac.user_role.attached', $actions);
        foreach ($rows as $r) {
            $this->assertSame('RBAC', $r['category']);
            $this->assertNull($r['actor_id']);
        }

        $after = $this->countAuditsForUser($u);
        $this->assertSame($before + 1, $after);
    }

    public function test_detach_non_assigned_role_is_noop_and_not_audited(): void
    {
        $u = $this->makeUser('Bob', 'bob@example.com');

        $before = $this->countAuditsForUser($u);

        $this->deleteJson("/rbac/users/{$u->id}/roles/Auditor")
            ->assertStatus(200);

        $after = $this->countAuditsForUser($u);
        $this->assertSame($before, $after);
    }

    public function test_attach_same_role_twice_emits_single_audit_record(): void
    {
        $u = $this->makeUser('Carol', 'carol@example.com');

        $this->postJson("/rbac/users/{$u->id}/roles/Auditor")->assertStatus(200);
        $firstCount = $this->countAuditsForUser($u);

        $this->postJson("/rbac/users/{$u->id}/roles/Auditor")->assertStatus(200);
        $secondCount = $this->countAuditsForUser($u);

        $this->assertSame($firstCount, $secondCount);
        $this->assertGreaterThanOrEqual(1, $firstCount);
    }

    public function test_replace_with_empty_set_clears_and_audits_removed(): void
    {
        $u = $this->makeUser('Dave', 'dave@example.com');
        $u->roles()->sync(['role_admin', 'role_auditor']);

        $this->putJson("/rbac/users/{$u->id}/roles", ['roles' => []])
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => []]);

        $events = AuditEvent::query()
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u->id)
            ->where('action', 'rbac.user_role.replaced')
            ->orderBy('occurred_at', 'desc')
            ->get();

        $this->assertCount(1, $events);

        $meta = $events->first()?->meta ?? [];
        $this->assertIsArray($meta);
        $removed = is_array($meta['removed'] ?? null) ? $meta['removed'] : [];
        $flat = array_values(array_filter(array_map(
            static fn ($v) => is_string($v) ? $v : null,
            $removed
        )));
        $flat = array_values(array_unique($flat));
        sort($flat);
        $this->assertSame(['Admin', 'Auditor'], $flat);
    }

    public function test_actor_id_is_set_when_authenticated_and_null_when_not(): void
    {
        config()->set('core.rbac.require_auth', false);
        $u1 = $this->makeUser('Eve', 'eve@example.com');
        $this->postJson("/rbac/users/{$u1->id}/roles/Auditor")->assertStatus(200);
        $unauthRow = DB::table('audit_events')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u1->id)
            ->where('action', 'rbac.user_role.attached')
            ->first();
        $this->assertNotNull($unauthRow);
        $this->assertNull($unauthRow->actor_id);

        config()->set('core.rbac.require_auth', true);
        $admin = $this->makeUser('Admin Two', 'admin2@example.com');
        $admin->roles()->sync(['role_admin']);
        Sanctum::actingAs($admin);

        $u2 = $this->makeUser('Frank', 'frank@example.com');
        $this->postJson("/rbac/users/{$u2->id}/roles/Auditor")->assertStatus(200);

        $authRow = DB::table('audit_events')
            ->where('entity_type', 'user')
            ->where('entity_id', (string) $u2->id)
            ->where('action', 'rbac.user_role.attached')
            ->first();
        $this->assertNotNull($authRow);
        $this->assertSame($admin->id, $authRow->actor_id);
    }
}
