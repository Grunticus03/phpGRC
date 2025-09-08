<?php declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['id' => 'role_admin',    'name' => 'Admin'],
            ['id' => 'role_auditor',  'name' => 'Auditor'],
            ['id' => 'role_risk_mgr', 'name' => 'Risk Manager'],
            ['id' => 'role_user',     'name' => 'User'],
        ];

        foreach ($roles as $r) {
            // Create if missing; don’t update the primary key on existing rows
            Role::query()->firstOrCreate(
                ['name' => $r['name']],
                ['id'   => $r['id']]
            );
        }
    }
}
