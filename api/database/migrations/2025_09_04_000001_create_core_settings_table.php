<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('core_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key', 191)->unique();
            $table->json('value')->nullable();
            $table->string('type', 32)->default('json');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_settings');
    }
};
