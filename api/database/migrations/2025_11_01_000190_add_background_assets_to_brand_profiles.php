<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table): void {
            $table->string('background_login_asset_id', 64)->nullable()->after('footer_logo_asset_id');
            $table->string('background_main_asset_id', 64)->nullable()->after('background_login_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table): void {
            $table->dropColumn(['background_login_asset_id', 'background_main_asset_id']);
        });
    }
};
