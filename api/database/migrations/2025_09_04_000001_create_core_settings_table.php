<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('core_settings')) {
            return;
        }

        Schema::create('core_settings', function (Blueprint $table): void {
            // Use string PK so upsert(['key']) works across drivers.
            $table->string('key')->primary();

            // Use TEXT for broad DB compatibility; app JSON-encodes values.
            $table->longText('value');

            // Small discriminator ("json" for now) to allow future types.
            $table->string('type', 16)->default('json');

            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_settings');
    }
};
