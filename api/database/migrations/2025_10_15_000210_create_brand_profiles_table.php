<?php

declare(strict_types=1);

use App\Models\UiSetting;
use App\Services\Settings\UiSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_PROFILE_ID = 'bp_default';
    private const MIGRATED_PROFILE_ID = 'bp_migrated';

    public function up(): void
    {
        Schema::create('brand_profiles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->string('title_text', 120);
            $table->string('favicon_asset_id', 64)->nullable();
            $table->string('primary_logo_asset_id', 64)->nullable();
            $table->string('secondary_logo_asset_id', 64)->nullable();
            $table->string('header_logo_asset_id', 64)->nullable();
            $table->string('footer_logo_asset_id', 64)->nullable();
            $table->boolean('footer_logo_disabled')->default(false);
            $table->timestampsTz();
        });

        Schema::table('brand_assets', function (Blueprint $table): void {
            $table->string('profile_id')
                ->after('id')
                ->default(self::DEFAULT_PROFILE_ID);

            $table->index('profile_id');
        });

        DB::table('brand_assets')->update(['profile_id' => self::DEFAULT_PROFILE_ID]);

        $now = now('UTC')->toDateTimeString();

        /** @var array<string,mixed> $defaults */
        $defaults = (array) config('ui.defaults.brand', []);

        $brand = [
            'title_text' => is_string($defaults['title_text'] ?? null) ? (string) $defaults['title_text'] : 'phpGRC',
            'favicon_asset_id' => $defaults['favicon_asset_id'] ?? null,
            'primary_logo_asset_id' => $defaults['primary_logo_asset_id'] ?? null,
            'secondary_logo_asset_id' => $defaults['secondary_logo_asset_id'] ?? null,
            'header_logo_asset_id' => $defaults['header_logo_asset_id'] ?? null,
            'footer_logo_asset_id' => $defaults['footer_logo_asset_id'] ?? null,
            'footer_logo_disabled' => (bool) ($defaults['footer_logo_disabled'] ?? false),
        ];

        $brandRows = UiSetting::query()
            ->where('key', 'like', 'ui.brand.%')
            ->get(['key', 'value', 'type']);

        foreach ($brandRows as $row) {
            $key = (string) $row->getAttribute('key');
            $path = substr($key, strlen('ui.brand.'));
            $valueRaw = (string) $row->getAttribute('value');
            $type = (string) $row->getAttribute('type');

            $decoded = match ($type) {
                'bool' => $valueRaw === '1',
                'int' => (int) $valueRaw,
                'float' => (float) $valueRaw,
                'json' => json_decode($valueRaw, true),
                default => $valueRaw,
            };

            switch ($path) {
                case 'title_text':
                    if (is_string($decoded) && trim($decoded) !== '') {
                        $brand['title_text'] = $decoded;
                    }
                    break;
                case 'favicon_asset_id':
                case 'primary_logo_asset_id':
                case 'secondary_logo_asset_id':
                case 'header_logo_asset_id':
                case 'footer_logo_asset_id':
                    $brand[$path] = is_string($decoded) && trim($decoded) !== '' ? $decoded : null;
                    break;
                case 'footer_logo_disabled':
                    $brand['footer_logo_disabled'] = (bool) $decoded;
                    break;
            }
        }

        /** @var UiSettingsService $settings */
        $settings = App::make(UiSettingsService::class);
        $brand = $settings->sanitizeBrandProfileData($brand);
        $defaultProfile = [
            'id' => self::DEFAULT_PROFILE_ID,
            'name' => 'Default',
            'is_default' => true,
            'is_active' => false,
            'is_locked' => true,
            'title_text' => is_string($defaults['title_text'] ?? null) ? (string) $defaults['title_text'] : 'phpGRC',
            'favicon_asset_id' => $defaults['favicon_asset_id'] ?? null,
            'primary_logo_asset_id' => $defaults['primary_logo_asset_id'] ?? null,
            'secondary_logo_asset_id' => $defaults['secondary_logo_asset_id'] ?? null,
            'header_logo_asset_id' => $defaults['header_logo_asset_id'] ?? null,
            'footer_logo_asset_id' => $defaults['footer_logo_asset_id'] ?? null,
            'footer_logo_disabled' => (bool) ($defaults['footer_logo_disabled'] ?? false),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('brand_profiles')->insert($defaultProfile);

        $hasCustomBranding = $brandRows->isNotEmpty();

        if ($hasCustomBranding) {
            DB::table('brand_profiles')->insert([
                'id' => self::MIGRATED_PROFILE_ID,
                'name' => 'Migrated Branding',
                'is_default' => false,
                'is_active' => true,
                'is_locked' => false,
                'title_text' => is_string($brand['title_text'] ?? null) && $brand['title_text'] !== ''
                    ? $brand['title_text']
                    : $defaultProfile['title_text'],
                'favicon_asset_id' => $brand['favicon_asset_id'] ?? null,
                'primary_logo_asset_id' => $brand['primary_logo_asset_id'] ?? null,
                'secondary_logo_asset_id' => $brand['secondary_logo_asset_id'] ?? null,
                'header_logo_asset_id' => $brand['header_logo_asset_id'] ?? null,
                'footer_logo_asset_id' => $brand['footer_logo_asset_id'] ?? null,
                'footer_logo_disabled' => (bool) ($brand['footer_logo_disabled'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('brand_assets')->update([
                'profile_id' => self::MIGRATED_PROFILE_ID,
            ]);
        } else {
            DB::table('brand_profiles')
                ->where('id', self::DEFAULT_PROFILE_ID)
                ->update(['is_active' => true]);
        }

        if ($brandRows->isNotEmpty()) {
            /** @var list<string> $keys */
            $keys = $brandRows->pluck('key')->all();
            UiSetting::query()
                ->whereIn('key', $keys)
                ->delete();
        }
    }

    public function down(): void
    {
        $activeProfile = DB::table('brand_profiles')
            ->where('is_active', true)
            ->first([
                'title_text',
                'favicon_asset_id',
                'primary_logo_asset_id',
                'secondary_logo_asset_id',
                'header_logo_asset_id',
                'footer_logo_asset_id',
                'footer_logo_disabled',
            ]);

        Schema::table('brand_assets', function (Blueprint $table): void {
            $table->dropIndex(['profile_id']);
            $table->dropColumn('profile_id');
        });

        Schema::dropIfExists('brand_profiles');

        if ($activeProfile === null) {
            return;
        }

        $now = now('UTC')->toDateTimeString();

        $entries = [
            'ui.brand.title_text' => [
                'value' => (string) ($activeProfile->title_text ?? 'phpGRC'),
                'type' => 'string',
            ],
            'ui.brand.favicon_asset_id' => [
                'value' => $activeProfile->favicon_asset_id,
                'type' => $activeProfile->favicon_asset_id === null ? 'json' : 'string',
            ],
            'ui.brand.primary_logo_asset_id' => [
                'value' => $activeProfile->primary_logo_asset_id,
                'type' => $activeProfile->primary_logo_asset_id === null ? 'json' : 'string',
            ],
            'ui.brand.secondary_logo_asset_id' => [
                'value' => $activeProfile->secondary_logo_asset_id,
                'type' => $activeProfile->secondary_logo_asset_id === null ? 'json' : 'string',
            ],
            'ui.brand.header_logo_asset_id' => [
                'value' => $activeProfile->header_logo_asset_id,
                'type' => $activeProfile->header_logo_asset_id === null ? 'json' : 'string',
            ],
            'ui.brand.footer_logo_asset_id' => [
                'value' => $activeProfile->footer_logo_asset_id,
                'type' => $activeProfile->footer_logo_asset_id === null ? 'json' : 'string',
            ],
            'ui.brand.footer_logo_disabled' => [
                'value' => (bool) ($activeProfile->footer_logo_disabled ?? false),
                'type' => 'bool',
            ],
        ];

        foreach ($entries as $key => $entry) {
            $value = $entry['value'];
            $type = $entry['type'];

            [$storedValue, $storedType] = match ($type) {
                'bool' => [$value ? '1' : '0', 'bool'],
                'json' => ['null', 'json'],
                default => [(string) $value, 'string'],
            };

            UiSetting::query()->insert([
                'key' => $key,
                'value' => $value === null ? 'null' : $storedValue,
                'type' => $value === null ? 'json' : $storedType,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
