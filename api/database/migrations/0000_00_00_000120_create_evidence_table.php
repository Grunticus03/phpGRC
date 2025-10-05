<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table): void {
            $table->string('id')->primary();                 // ev_<ULID>
            $table->unsignedBigInteger('owner_id');          // fk users.id (deferred)
            $table->string('filename');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->unsignedInteger('version')->default(1);
            $table->binary('bytes');                         // upgraded to LONGBLOB below

            // Timestamps
            $table->dateTimeTz('created_at')->useCurrent();
            $table->dateTimeTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Indexes
            $table->index(['owner_id', 'filename']);
            $table->index('sha256');
            $table->index(['created_at', 'id'], 'evidence_created_id_idx');
        });

        // Ensure capacity for 25MB+ uploads on MySQL by using LONGBLOB.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE evidence MODIFY bytes LONGBLOB');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
