<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $persist = (config('core.rbac.mode') === 'persist') || (bool) config('core.rbac.persistence', false);

        if ($persist) {
            $this->call([
                RolesSeeder::class,
            ]);
        }
    }
}

