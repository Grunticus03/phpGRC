<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idp_providers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('driver', 40);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('evaluation_order')->default(1);
            $table->text('config');
            $table->json('meta')->nullable();
            $table->timestampTz('last_health_at')->nullable();
            $table->timestampsTz();

            $table->unique('evaluation_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idp_providers');
    }
};
