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
        Schema::create('brand_assets', function (Blueprint $table): void {
            $table->string('id')->primary(); // ba_<ULID>
            $table->string('kind', 32);
            $table->string('name', 160);
            $table->string('mime', 96);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->binary('bytes');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name', 120)->nullable();
            $table->dateTimeTz('created_at')->useCurrent();
            $table->dateTimeTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('kind');
            $table->index('created_at');
            $table->index('sha256');

            $table->foreign('uploaded_by')
                ->references('id')
                ->on('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE brand_assets MODIFY bytes LONGBLOB');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_assets');
    }
};
