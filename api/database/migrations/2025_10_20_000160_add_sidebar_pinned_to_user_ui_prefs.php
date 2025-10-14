<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_ui_prefs', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_ui_prefs', 'sidebar_pinned')) {
                $table->boolean('sidebar_pinned')
                    ->default(true)
                    ->after('sidebar_collapsed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_ui_prefs', function (Blueprint $table): void {
            if (Schema::hasColumn('user_ui_prefs', 'sidebar_pinned')) {
                $table->dropColumn('sidebar_pinned');
            }
        });
    }
};
