<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('evidence') && !Schema::hasColumn('evidence', 'updated_at')) {
            Schema::table('evidence', function (Blueprint $table): void {
                // Match created_at type; allow null for older rows.
                $table->dateTimeTz('updated_at')->nullable()->after('created_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evidence') && Schema::hasColumn('evidence', 'updated_at')) {
            Schema::table('evidence', function (Blueprint $table): void {
                $table->dropColumn('updated_at');
            });
        }
    }
};
