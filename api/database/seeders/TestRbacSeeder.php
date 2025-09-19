<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class TestRbacSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Admin', 'Auditor', 'Risk Manager', 'User'] as $name) {
            $id = 'role_' . Str::slug($name, '_');
            Role::query()->updateOrCreate(['id' => $id], ['name' => $name]);
        }
    }
}

