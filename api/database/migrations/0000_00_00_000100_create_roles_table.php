<?php

declare(strict_types=1);

// Phase 4 placeholder migration. Do not execute this phase.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->string('id')->primary();   // e.g., role_0001 (stub)
            $table->string('name')->unique();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
