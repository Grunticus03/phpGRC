<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_theme_pack_files', function (Blueprint $table): void {
            $table->id();
            $table->string('pack_slug');
            $table->string('path', 255);
            $table->string('mime', 96);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->binary('bytes');
            $table->dateTimeTz('created_at')->useCurrent();
            $table->dateTimeTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['pack_slug', 'path']);
            $table->index('pack_slug');
            $table->index('created_at');

            $table->foreign('pack_slug')
                ->references('slug')
                ->on('ui_theme_packs')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ui_theme_pack_files MODIFY bytes LONGBLOB');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_theme_pack_files');
    }
};
