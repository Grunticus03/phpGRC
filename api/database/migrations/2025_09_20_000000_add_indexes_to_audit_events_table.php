<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        Schema::table('audit_events', function (Blueprint $table): void {
            // Short names to satisfy MySQL 64-byte index name limit
            $table->index(['category', 'occurred_at'], 'idx_audit_cat_occurred_at');
            $table->index(['action', 'occurred_at'], 'idx_audit_action_occurred_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('idx_audit_cat_occurred_at');
            $table->dropIndex('idx_audit_action_occurred_at');
        });
    }
};
