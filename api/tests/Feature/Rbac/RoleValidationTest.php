<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RoleValidationTest extends TestCase
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
        $adminId = Role::query()->where('name', 'Admin')->value('id');
        if (is_string($adminId)) {
            $u->roles()->syncWithoutDetaching([$adminId]);
        }
        $this->admin = $u;
    }

    public function test_replace_rejects_length_and_duplicate_after_normalization(): void
    {
        $uid = $this->admin->id;

        $res1 = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/rbac/users/{$uid}/roles", ['roles' => [' a ']]);

        $res1->assertStatus(422);
        $payload1 = $res1->json();
        $this->assertIsArray($payload1);
        $this->assertTrue(isset($payload1['code']) || isset($payload1['errors']));
        if (isset($payload1['code'])) {
            $this->assertSame('VALIDATION_FAILED', $payload1['code']);
        }

        $res2 = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/rbac/users/{$uid}/roles", ['roles' => ['Auditor', '  auditor  ']]);

        $res2->assertStatus(422);
        $payload2 = $res2->json();
        $this->assertIsArray($payload2);
        $this->assertTrue(isset($payload2['code']) || isset($payload2['errors']));
        if (isset($payload2['code'])) {
            $this->assertSame('VALIDATION_FAILED', $payload2['code']);
        }
    }

    public function test_attach_and_detach_normalize_and_match_case_insensitively(): void
    {
        Role::query()->firstOrCreate(
            ['id' => 'role_risk_manager'],
            ['name' => 'Risk Manager']
        );

        $u = \Database\Factories\UserFactory::new()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/rbac/users/{$u->id}/roles/Risk%20%20Manager")
            ->assertStatus(200)
            ->assertJson(fn ($j) => $j
                ->where('ok', true)
                ->whereType('roles', 'array')
                ->etc()
            );

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/rbac/users/{$u->id}/roles/risk manager")
            ->assertStatus(200)
            ->assertJson(fn ($j) => $j
                ->whereType('roles', 'array')
                ->etc()
            );
    }

    public function test_replace_errors_on_unknown_roles(): void
    {
        $u = \Database\Factories\UserFactory::new()->create();
        $missing = 'Not_A_Real_' . Str::random(6);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/rbac/users/{$u->id}/roles", ['roles' => [$missing]])
            ->assertStatus(422)
            ->assertJson(fn ($j) => $j
                ->where('ok', false)
                ->where('code', 'ROLE_NOT_FOUND')
                ->whereContains('missing_roles', $missing)
                ->etc()
            );
    }
}

