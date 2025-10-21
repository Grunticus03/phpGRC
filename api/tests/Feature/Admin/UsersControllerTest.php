<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Factories\UserFactory;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UsersControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
        ]);

        $this->seed(TestRbacSeeder::class);
    }

    private function makeAdmin(): User
    {
        $admin = UserFactory::new()->create();
        $adminRole = Role::query()->where('name', 'Admin')->value('id');
        if (is_string($adminRole) && $adminRole !== '') {
            $admin->roles()->sync([$adminRole]);
        }

        return $admin;
    }

    public function test_query_parameter_filters_users_by_name_or_email(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $alpha = UserFactory::new()->create([
            'name' => 'Alpha Tester',
            'email' => 'alpha.tester@example.test',
        ]);
        UserFactory::new()->create([
            'name' => 'Bravo Example',
            'email' => 'bravo@example.test',
        ]);

        $response = $this->getJson('/users?q=alpha');
        $response->assertOk();

        $payload = $response->json();
        self::assertIsArray($payload);
        self::assertTrue($payload['ok'] ?? false);
        $data = $payload['data'] ?? [];
        self::assertCount(1, $data);
        self::assertSame($alpha->id, $data[0]['id'] ?? null);
    }

    public function test_wildcard_query_matches_roles_and_emails(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $auditorRole = Role::query()->where('name', 'Auditor')->value('id');

        $charlie = UserFactory::new()->create([
            'name' => 'Charlie Reviewer',
            'email' => 'charlie.reviewer@example.test',
        ]);
        if (is_string($auditorRole) && $auditorRole !== '') {
            $charlie->roles()->sync([$auditorRole]);
        }

        UserFactory::new()->create([
            'name' => 'Delta Person',
            'email' => 'delta@example.test',
        ]);

        $response = $this->getJson('/users?q=charlie*');
        $response->assertOk();

        $payload = $response->json();
        self::assertIsArray($payload);
        $data = $payload['data'] ?? [];
        self::assertCount(1, $data);
        self::assertSame('Charlie Reviewer', $data[0]['name'] ?? null);
    }

    public function test_update_accepts_slugged_role_names(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        Role::query()->updateOrCreate(
            ['id' => 'role_theme_manager'],
            ['name' => 'Theme Manager']
        );
        Role::query()->updateOrCreate(
            ['id' => 'role_theme_auditor'],
            ['name' => 'Theme Auditor']
        );

        $user = UserFactory::new()->create();

        $response = $this->putJson('/users/'.$user->id, [
            'roles' => ['theme_manager', 'Theme Auditor'],
        ]);
        $response->assertOk();

        $payload = $response->json();
        self::assertIsArray($payload);
        self::assertTrue($payload['ok'] ?? false);

        $user->refresh();
        /** @var list<string> $assignedNames */
        $assignedNames = $user->roles()
            ->pluck('name')
            ->filter(static fn ($name): bool => is_string($name))
            ->values()
            ->all();

        self::assertContains('Theme Manager', $assignedNames);
        self::assertContains('Theme Auditor', $assignedNames);
    }
}
