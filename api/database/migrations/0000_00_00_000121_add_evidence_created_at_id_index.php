<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('evidence')) {
            Schema::table('evidence', function (Blueprint $table): void {
                $table->index(['created_at', 'id'], 'evidence_created_id_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evidence')) {
            Schema::table('evidence', function (Blueprint $table): void {
                $table->dropIndex('evidence_created_id_idx');
            });
        }
    }
};
