<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.persistence' => true,
            'core.rbac.require_auth' => false,
            'core.audit.enabled' => true,
        ]);

        $this->seed(TestRbacSeeder::class);
    }

    private function makeAuditor(): User
    {
        $u = \Database\Factories\UserFactory::new()->create();
        $auditorId = Role::query()->where('name', 'Auditor')->value('id');
        if (is_string($auditorId)) {
            $u->roles()->syncWithoutDetaching([$auditorId]);
        }

        return $u;
    }

    private function makeUser(): User
    {
        return \Database\Factories\UserFactory::new()->create();
    }

    /** @return array<string,mixed> */
    private function evt(array $overrides = []): array
    {
        $base = \Database\Factories\AuditEventFactory::new()->definition();

        return array_merge($base, $overrides, [
            'id' => (string) Str::ulid(),
            'occurred_at' => now('UTC'),
            'created_at' => now('UTC'),
        ]);
    }

    public function test_index_filters_by_category_and_action(): void
    {
        $auditor = $this->makeAuditor();

        // Seed events of multiple categories/actions.
        \App\Models\AuditEvent::query()->insert([
            $this->evt(['category' => 'RBAC', 'action' => 'rbac.user_role.attached', 'entity_type' => 'user', 'entity_id' => '42']),
            $this->evt(['category' => 'RBAC', 'action' => 'rbac.user_role.detached', 'entity_type' => 'user', 'entity_id' => '42']),
            $this->evt(['category' => 'AUTH', 'action' => 'auth.login', 'entity_type' => 'user', 'entity_id' => '99']),
        ]);

        $this->actingAs($auditor, 'sanctum');

        // Filter by category
        $res = $this->getJson('/audit?category=RBAC&limit=50');
        $res->assertStatus(200)
            ->assertJson(['ok' => true]);

        $data = $res->json('items');
        $this->assertIsArray($data);
        $actions = array_values(array_map(static fn ($i) => $i['action'] ?? '', $data));
        $this->assertContains('rbac.user_role.attached', $actions);
        $this->assertContains('rbac.user_role.detached', $actions);
        $this->assertNotContains('auth.login', $actions);

        // Filter by action
        $res2 = $this->getJson('/audit?action=rbac.user_role.attached&limit=50');
        $res2->assertStatus(200)
            ->assertJson(['ok' => true]);

        $data2 = $res2->json('items');
        $this->assertIsArray($data2);
        $this->assertTrue(
            collect($data2)->every(static fn ($i) => ($i['action'] ?? null) === 'rbac.user_role.attached')
        );
    }

    public function test_csv_export_respects_filters_and_headers(): void
    {
        $auditor = $this->makeAuditor();

        \App\Models\AuditEvent::query()->insert([
            $this->evt(['category' => 'RBAC', 'action' => 'rbac.user_role.attached', 'entity_type' => 'user', 'entity_id' => '7']),
            $this->evt(['category' => 'AUTH', 'action' => 'auth.login', 'entity_type' => 'user', 'entity_id' => '8']),
        ]);

        $this->actingAs($auditor, 'sanctum');

        $res = $this->get('/audit/export.csv?category=RBAC&order=asc');

        $res->assertStatus(200);
        $this->assertSame('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('rbac.user_role.attached', $res->getContent() ?: '');
        $this->assertStringNotContainsString('auth.login', $res->getContent() ?: '');
    }

    public function test_forbidden_without_required_role(): void
    {
        $user = $this->makeUser(); // no Auditor role
        $this->actingAs($user, 'sanctum');

        $res = $this->getJson('/audit?limit=1');
        $res->assertStatus(403);
    }
}
