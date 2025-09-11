<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        // Safety: only seed when persistence is enabled and table exists.
        $persist = (config('core.rbac.mode') === 'persist') || (bool) config('core.rbac.persistence', false);
        if (!$persist || !Schema::hasTable('roles')) {
            return;
        }

        $roles = [
            ['id' => 'role_admin',    'name' => 'Admin'],
            ['id' => 'role_auditor',  'name' => 'Auditor'],
            ['id' => 'role_risk_mgr', 'name' => 'Risk Manager'],
            ['id' => 'role_user',     'name' => 'User'],
        ];

        foreach ($roles as $r) {
            Role::query()->firstOrCreate(
                ['name' => $r['name']],
                ['id'   => $r['id']]
            );
        }
    }
}

