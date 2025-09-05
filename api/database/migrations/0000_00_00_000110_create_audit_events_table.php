<?php

declare(strict_types=1);

// Phase 4 placeholder migration. Do not execute this phase.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->string('id')->primary();                 // e.g., ae_0001 (stub)
            $table->dateTimeTz('occurred_at');               // event time
            $table->unsignedBigInteger('actor_id')->nullable(); // FK users.id (nullable)
            $table->string('action');                        // e.g., settings.update
            $table->string('entity_type');                   // e.g., core.settings
            $table->string('entity_id');                     // e.g., core.rbac.enabled
            $table->string('ip')->nullable();
            $table->string('ua')->nullable();
            $table->json('meta')->nullable();
            $table->dateTimeTz('created_at')->useCurrent();  // creation record time
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
