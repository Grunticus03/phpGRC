<?php

declare(strict_types=1);

// Phase 4 placeholder migration. Do not execute this phase.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatars', function (Blueprint $table): void {
            $table->string('id')->primary();              // e.g., av_0001 (stub)
            $table->unsignedBigInteger('user_id')->unique(); // one avatar per user
            $table->string('path');                       // storage path (deferred)
            $table->string('mime', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatars');
    }
};
