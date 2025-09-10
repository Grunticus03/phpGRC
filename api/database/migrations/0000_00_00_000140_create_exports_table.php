<?php

declare(strict_types=1);

// Phase 4 placeholder migration. Execute once persistence is enabled.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            // 26-char ULID primary key
            $table->string('id', 26)->primary();

            // Export meta
            $table->string('type', 16);              // csv | json | pdf
            $table->json('params')->nullable();      // request parameters

            // Lifecycle
            $table->string('status', 32)->index();   // pending|running|completed|failed
            $table->unsignedTinyInteger('progress')->default(0);

            // Artifact metadata (set when completed)
            $table->string('artifact_disk', 64)->nullable();
            $table->string('artifact_path', 191)->nullable();
            $table->string('artifact_mime', 191)->nullable();
            $table->unsignedBigInteger('artifact_size')->nullable();
            $table->string('artifact_sha256', 64)->nullable();

            // Timestamps
            $table->dateTimeTz('created_at')->useCurrent();
            $table->dateTimeTz('completed_at')->nullable();
            $table->dateTimeTz('failed_at')->nullable();

            // Error details (if failed)
            $table->string('error_code', 64)->nullable();
            $table->string('error_note', 191)->nullable();

            // Indexes helpful for ops
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};

