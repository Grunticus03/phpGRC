<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->string('type', 16);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTimeTz('created_at')->useCurrent();
            $table->dateTimeTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_settings');
    }
};
