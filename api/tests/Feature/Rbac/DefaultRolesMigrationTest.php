<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DefaultRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_roles_exist_after_migration(): void
    {
        $defaults = Role::query()
            ->whereIn('id', ['role_admin', 'role_auditor', 'role_risk_manager', 'role_user'])
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();

        $this->assertSame([
            'role_admin' => 'Admin',
            'role_auditor' => 'Auditor',
            'role_risk_manager' => 'Risk Manager',
            'role_user' => 'User',
        ], $defaults);
    }
}
