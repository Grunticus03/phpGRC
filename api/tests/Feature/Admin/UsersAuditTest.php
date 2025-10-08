<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditCategories;
use Database\Factories\UserFactory;
use Database\Seeders\TestRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UsersAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.audit.enabled' => true,
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
        ]);

        $this->seed(TestRbacSeeder::class);
    }

    private function makeAdmin(): User
    {
        $user = UserFactory::new()->create();
        $adminId = Role::query()->where('name', 'Admin')->value('id');
        if (is_string($adminId) && $adminId !== '') {
            $user->roles()->sync([$adminId]);
        }

        return $user;
    }

    public function test_user_creation_logs_audit_event(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/admin/users', [
            'name' => 'Audit Target',
            'email' => 'audit.target@example.test',
            'password' => 'Secret123!',
            'roles' => ['Auditor'],
        ])->assertCreated();

        /** @var AuditEvent|null $event */
        $event = AuditEvent::query()->where('action', 'rbac.user.created')->first();
        self::assertNotNull($event, 'Expected rbac.user.created event');
        self::assertSame(AuditCategories::RBAC, $event->category);
        self::assertSame('user', $event->entity_type);
        self::assertNotEmpty($event->entity_id);

        $meta = $event->meta ?? [];
        self::assertIsArray($meta);
        self::assertSame('Audit Target', $meta['target_username'] ?? null);
        self::assertSame('audit.target@example.test', $meta['target_email'] ?? null);
        $roles = $meta['roles'] ?? [];
        self::assertIsArray($roles);
        self::assertContains('Auditor', $roles);
    }

    public function test_user_deletion_logs_audit_event(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $target = UserFactory::new()->create();
        $auditorRole = Role::query()->where('name', 'Auditor')->value('id');
        if (is_string($auditorRole) && $auditorRole !== '') {
            $target->roles()->sync([$auditorRole]);
        }

        $this->deleteJson('/admin/users/'.$target->id)->assertOk();

        /** @var AuditEvent|null $event */
        $event = AuditEvent::query()->where('action', 'rbac.user.deleted')->first();
        self::assertNotNull($event, 'Expected rbac.user.deleted event');
        self::assertSame((string) $target->id, $event->entity_id);
        self::assertSame('user', $event->entity_type);

        $meta = $event->meta ?? [];
        self::assertIsArray($meta);
        self::assertSame($target->name, $meta['target_username'] ?? null);
        self::assertSame($target->email, $meta['target_email'] ?? null);
    }
}
