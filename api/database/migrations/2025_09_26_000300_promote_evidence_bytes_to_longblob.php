<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('evidence')) {
            return;
        }

        DB::statement('ALTER TABLE evidence MODIFY bytes LONGBLOB');
    }

    public function down(): void
    {
        // No-op on purpose: shrinking back to BLOB could truncate existing data.
    }
};
