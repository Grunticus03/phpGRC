<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_theme_packs', function (Blueprint $table): void {
            $table->string('slug')->primary();
            $table->string('name', 160);
            $table->string('version', 64)->nullable();
            $table->string('author', 160)->nullable();
            $table->string('license_name', 120)->nullable();
            $table->string('license_file', 160)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->string('imported_by_name', 120)->nullable();
            $table->json('assets')->nullable();
            $table->json('files')->nullable();
            $table->json('inactive')->nullable();
            $table->dateTimeTz('created_at')->useCurrent();
            $table->dateTimeTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('enabled');
            $table->index('created_at');

            $table->foreign('imported_by')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_theme_packs');
    }
};
