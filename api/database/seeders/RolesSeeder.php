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
        if (!Schema::hasTable('roles')) {
            return;
        }

        $shouldSeed = app()->runningUnitTests()
            || (string) config('core.rbac.mode', 'stub') === 'persist'
            || (bool) (config('core.rbac.persistence') ?? false);

        if (!$shouldSeed) {
            return;
        }

        $seed = [
            ['id' => 'role_admin',    'name' => 'Admin'],
            ['id' => 'role_auditor',  'name' => 'Auditor'],
            ['id' => 'role_risk_mgr', 'name' => 'Risk Manager'],
            ['id' => 'role_user',     'name' => 'User'],
        ];

        foreach ($seed as $r) {
            Role::query()->firstOrCreate(['id' => $r['id']], ['name' => $r['name']]);
        }
    }
}
