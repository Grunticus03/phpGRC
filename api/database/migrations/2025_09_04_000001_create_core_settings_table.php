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
            $table->string('key', 191)->unique(); // dotted key, e.g., core.audit.retention_days
            $table->json('value')->nullable();    // JSON-encoded scalar/array
            $table->string('type', 32)->default('json'); // reserved
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

