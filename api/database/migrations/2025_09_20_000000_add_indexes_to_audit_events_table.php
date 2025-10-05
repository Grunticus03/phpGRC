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

        // Create each index independently; ignore duplicates.
        try {
            Schema::table('audit_events', function (Blueprint $table): void {
                $table->index(['category', 'occurred_at'], 'idx_audit_cat_occurred_at');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (stripos($e->getMessage(), 'Duplicate key name') === false) {
                throw $e;
            }
        }

        try {
            Schema::table('audit_events', function (Blueprint $table): void {
                $table->index(['action', 'occurred_at'], 'idx_audit_action_occurred_at');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (stripos($e->getMessage(), 'Duplicate key name') === false) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        // Drop if present; ignore missing.
        try {
            Schema::table('audit_events', function (Blueprint $table): void {
                $table->dropIndex('idx_audit_cat_occurred_at');
            });
        } catch (\Throwable $e) {
            // no-op
        }

        try {
            Schema::table('audit_events', function (Blueprint $table): void {
                $table->dropIndex('idx_audit_action_occurred_at');
            });
        } catch (\Throwable $e) {
            // no-op
        }
    }
};
