<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ui_settings')) {
            return;
        }

        $now = now('UTC')->toDateTimeString();

        $rows = [
            ['key' => 'ui.theme.default', 'value' => 'slate', 'type' => 'string'],
            ['key' => 'ui.theme.allow_user_override', 'value' => '1', 'type' => 'bool'],
            ['key' => 'ui.theme.force_global', 'value' => '0', 'type' => 'bool'],
            [
                'key' => 'ui.theme.overrides',
                'value' => json_encode([
                    'color.primary' => '#0d6efd',
                    'color.surface' => '#1b1e21',
                    'color.text' => '#f8f9fa',
                    'shadow' => 'default',
                    'spacing' => 'default',
                    'typeScale' => 'medium',
                    'motion' => 'full',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'json',
            ],
            [
                'key' => 'ui.nav.sidebar.default_order',
                'value' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'json',
            ],
            ['key' => 'ui.brand.title_text', 'value' => 'phpGRC — Dashboard', 'type' => 'string'],
            ['key' => 'ui.brand.favicon_asset_id', 'value' => 'null', 'type' => 'json'],
            ['key' => 'ui.brand.primary_logo_asset_id', 'value' => 'null', 'type' => 'json'],
            ['key' => 'ui.brand.secondary_logo_asset_id', 'value' => 'null', 'type' => 'json'],
            ['key' => 'ui.brand.header_logo_asset_id', 'value' => 'null', 'type' => 'json'],
            ['key' => 'ui.brand.footer_logo_asset_id', 'value' => 'null', 'type' => 'json'],
            ['key' => 'ui.brand.footer_logo_disabled', 'value' => '0', 'type' => 'bool'],
        ];

        foreach ($rows as &$row) {
            $row['updated_by'] = null;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::table('ui_settings')->upsert($rows, ['key'], ['value', 'type', 'updated_at', 'updated_by']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ui_settings')) {
            return;
        }

        DB::table('ui_settings')->whereIn('key', [
            'ui.theme.default',
            'ui.theme.allow_user_override',
            'ui.theme.force_global',
            'ui.theme.overrides',
            'ui.nav.sidebar.default_order',
            'ui.brand.title_text',
            'ui.brand.favicon_asset_id',
            'ui.brand.primary_logo_asset_id',
            'ui.brand.secondary_logo_asset_id',
            'ui.brand.header_logo_asset_id',
            'ui.brand.footer_logo_asset_id',
            'ui.brand.footer_logo_disabled',
        ])->delete();
    }
};
