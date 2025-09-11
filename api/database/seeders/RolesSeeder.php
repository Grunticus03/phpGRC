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

        // Seed always during tests; in non-test envs, seed when persistence is enabled.
        $shouldSeed = app()->runningUnitTests()
            || config('core.rbac.mode') === 'persist'
            || (bool) (config('core.rbac.persistence') ?? false);

        if (!$shouldSeed) {
            return;
        }

        foreach (['Admin', 'Auditor', 'Risk Manager', 'User'] as $name) {
            Role::query()->firstOrCreate(['name' => $name]); // id auto via HasUlids
        }
    }
}
