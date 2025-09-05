<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
// use Illuminate\Database\Schema\Blueprint;
// use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Phase 4 stub migration.
     * INTENT: Do nothing at runtime. Schema shown below for later enablement.
     *
     * // TODO (enable later):
     * // Schema::create('avatars', function (Blueprint $table): void {
     * //     $table->id();
     * //     $table->unsignedBigInteger('user_id'); // fk users.id
     * //     $table->string('path', 255);
     * //     $table->string('mime', 64);
     * //     $table->unsignedBigInteger('size_bytes');
     * //     $table->timestamps();
     * //     $table->unique(['user_id']);
     * // });
     */
    public function up(): void
    {
        // no-op (stub only)
    }

    /**
     * // TODO (enable later):
     * // Schema::dropIfExists('avatars');
     */
    public function down(): void
    {
        // no-op (stub only)
    }
};
