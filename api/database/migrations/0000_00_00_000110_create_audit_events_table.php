<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: enable persistence for audit events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            // 26-char ULID primary key
            $table->string('id', 26)->primary();

            // Event occurrence timestamp (immutable)
            $table->dateTimeTz('occurred_at')->index();

            // Optional actor
            $table->unsignedBigInteger('actor_id')->nullable()->index();

            // Action and category
            $table->string('action', 191)->index();
            $table->string('category', 64)->index();

            // Target entity
            $table->string('entity_type', 128)->index();
            $table->string('entity_id', 191)->index();

            // Network and agent
            $table->string('ip', 45)->nullable();
            $table->string('ua', 255)->nullable();

            // Arbitrary JSON metadata
            $table->json('meta')->nullable();

            // Creation time of the record
            $table->dateTimeTz('created_at')->useCurrent();

            // Helpful compound index for retention + scans
            $table->index(['occurred_at', 'id'], 'ae_occurred_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
