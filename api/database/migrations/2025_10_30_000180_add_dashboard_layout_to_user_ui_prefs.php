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
            if (! Schema::hasColumn('user_ui_prefs', 'dashboard_layout')) {
                $table->text('dashboard_layout')->nullable()->after('sidebar_hidden');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_ui_prefs', function (Blueprint $table): void {
            if (Schema::hasColumn('user_ui_prefs', 'dashboard_layout')) {
                $table->dropColumn('dashboard_layout');
            }
        });
    }
};
