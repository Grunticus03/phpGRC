<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seeds the roles table from config('core.rbac.roles').
 * Safe in Phase 4: no-ops if the table does not exist.
 */
final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('roles')) {
            return; // Phase 4: placeholder migration not applied yet
        }

        $roles = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

        foreach ($roles as $name) {
            $name = (string) $name;

            $exists = DB::table('roles')->where('name', $name)->exists();
            if ($exists) {
                // Touch updated_at to reflect seeding pass.
                DB::table('roles')
                    ->where('name', $name)
                    ->update(['updated_at' => now()]);
                continue;
            }

            // Deterministic primary key from role name for readability.
            $id = 'role_' . Str::slug($name, '_');

            DB::table('roles')->insert([
                'id'         => $id,
                'name'       => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
