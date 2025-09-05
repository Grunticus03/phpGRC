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
     * // Schema::create('evidence', function (Blueprint $table): void {
     * //     $table->id();
     * //     $table->unsignedBigInteger('owner_id'); // fk users.id
     * //     $table->string('filename', 255);
     * //     $table->string('mime', 128);
     * //     $table->unsignedBigInteger('size_bytes');
     * //     $table->char('sha256', 64);
     * //     $table->dateTime('created_at');
     * //     $table->index(['owner_id']);
     * //     $table->index(['sha256']);
     * // });
     */
    public function up(): void
    {
        // no-op (stub only)
    }

    /**
     * // TODO (enable later):
     * // Schema::dropIfExists('evidence');
     */
    public function down(): void
    {
        // no-op (stub only)
    }
};
