<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ui_prefs', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('theme', 64)->nullable();
            $table->string('mode', 16)->nullable();
            $table->text('overrides')->nullable(); // JSON encoded map
            $table->boolean('sidebar_collapsed')->default(false);
            $table->unsignedInteger('sidebar_width')->default(280);
            $table->text('sidebar_order')->nullable(); // JSON encoded list
            $table->dateTimeTz('created_at')->useCurrent();
            $table->dateTimeTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ui_prefs');
    }
};
