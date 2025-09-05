<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Placeholder only. No schema until execution phase.
    public function up(): void
    {
        // TODO: define roles table when persistence is enabled.
        // Example (deferred):
        // Schema::create('roles', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('name')->unique();
        //     $table->timestamps();
        // });
    }

    public function down(): void
    {
        // TODO: Schema::dropIfExists('roles');
    }
};
