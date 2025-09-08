<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string,string> $defaults */
        $defaults = [
            'role_admin'   => 'Admin',
            'role_auditor' => 'Auditor',
        ];

        foreach ($defaults as $id => $name) {
            /** @var array{id:string,name:string} $attrs */
            $attrs = ['id' => $id, 'name' => $name];
            Role::query()->updateOrCreate(['id' => $id], $attrs);
        }
    }
}

