<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RbacUserRolesEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', false); // allow anonymous for simple calls
        $this->seed(RolesSeeder::class); // Admin, Auditor, Risk Manager
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name'     => $name,
            'email'    => $email,
            'password' => bcrypt('secret'),
        ]);
    }

    public function test_attach_accepts_mixed_case_and_whitespace(): void
    {
        $u = $this->user('Casey', 'casey@example.com');

        // Use PUT body to test whitespace + case normalization without illegal spaces in the URI.
        $this->putJson("/rbac/users/{$u->id}/roles", ['roles' => ['  aUdItOr  ']])
            ->assertStatus(200)
            ->assertJsonFragment(['roles' => ['Auditor']]);
    }

    public function test_attach_role_name_over_64_chars_rejected(): void
    {
        $u = $this->user('Longy', 'longy@example.com');

        $tooLong = str_repeat('A', 65);

        $this->postJson("/rbac/users/{$u->id}/roles/{$tooLong}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJson(fn ($json) => $json->whereType('errors.role', 'array')->etc());
    }
}
