<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

final class TestRbacSeeder extends Seeder
{
    public function run(): void
    {
        $seed = [
            'Admin' => 'role_admin',
            'Auditor' => 'role_auditor',
            'Risk Manager' => 'role_risk_mgr',
            'User' => 'role_user',
        ];

        foreach ($seed as $name => $id) {
            Role::query()->updateOrCreate(['id' => $id], ['name' => $name]);
        }
    }
}
