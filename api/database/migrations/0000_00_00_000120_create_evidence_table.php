<?php

declare(strict_types=1);

// Phase 4 placeholder migration. Do not execute this phase.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table): void {
            $table->string('id')->primary();                 // e.g., ev_0001 (stub)
            $table->unsignedBigInteger('owner_id');          // fk users.id (deferred)
            $table->string('filename');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);                    // placeholder; write deferred
            $table->dateTimeTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
