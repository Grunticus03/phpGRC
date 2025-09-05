<?php

declare(strict_types=1);

// Phase 4 placeholder migration. Do not execute this phase.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            $table->string('id')->primary();         // e.g., exp_0001 (stub)
            $table->string('type', 16);              // csv | json | pdf
            $table->json('params')->nullable();      // request parameters
            $table->string('status', 32)->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->dateTimeTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
