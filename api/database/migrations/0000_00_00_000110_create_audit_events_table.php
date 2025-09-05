<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
// use Illuminate\Database\Schema\Blueprint;
// use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Phase 4 stub migration.
     * INTENT: Do nothing at runtime. Schema shown below for later enablement.
     *
     * // TODO (enable later):
     * // Schema::create('audit_events', function (Blueprint $table): void {
     * //     $table->id();
     * //     $table->dateTime('occurred_at');
     * //     $table->unsignedBigInteger('actor_id')->nullable(); // fk users.id
     * //     $table->string('action', 128);
     * //     $table->string('entity_type', 128)->nullable();
     * //     $table->string('entity_id', 128)->nullable();
     * //     $table->string('ip', 64)->nullable();
     * //     $table->string('ua', 255)->nullable();
     * //     $table->json('meta')->nullable();
     * //     $table->dateTime('created_at');
     * //     $table->index(['occurred_at']);
     * // });
     */
    public function up(): void
    {
        // no-op (stub only)
    }

    /**
     * // TODO (enable later):
     * // Schema::dropIfExists('audit_events');
     */
    public function down(): void
    {
        // no-op (stub only)
    }
};
