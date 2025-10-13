<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\BrandProfile;
use App\Models\UiSetting;
use App\Services\Settings\Exceptions\BrandProfileLockedException;
use App\Services\Settings\Exceptions\BrandProfileNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UiSettingsService
{
    /** @var list<string> */
    private const STORAGE_KEYS = [
        'ui.theme.default',
        'ui.theme.allow_user_override',
        'ui.theme.force_global',
        'ui.theme.overrides',
        'ui.theme.designer.storage',
        'ui.theme.designer.filesystem_path',
        'ui.nav.sidebar.default_order',
        'ui.brand.assets.filesystem_path',
    ];

    private const DEFAULT_PROFILE_ID = 'bp_default';

    public function __construct(
        private readonly ThemePackService $themePacks,
        private readonly BrandAssetStorageService $brandAssets
    ) {}

    /**
     * @return array{
     *     theme: array{
     *         default: string,
     *         allow_user_override: bool,
     *         force_global: bool,
     *         overrides: array<string,string|null>,
     *         designer: array{storage: string, filesystem_path: string}
     *     },
     *     nav: array{sidebar: array{default_order: array<int,string>}},
     *     brand: array{
     *         title_text: string,
     *         favicon_asset_id: string|null,
     *         primary_logo_asset_id: string|null,
     *         secondary_logo_asset_id: string|null,
     *         header_logo_asset_id: string|null,
     *         footer_logo_asset_id: string|null,
     *         footer_logo_disabled: bool,
     *         assets: array{filesystem_path: string}
     *     }
     * }
     */
    public function currentConfig(): array
    {
        /** @var Collection<int, UiSetting> $rows */
        $rows = UiSetting::query()
            ->orderBy('key')
            ->get(['key', 'value', 'type']);

        /** @var array<string,mixed> $config */
        $config = [];

        foreach ($rows as $row) {
            /** @var string $key */
            $key = $row->getAttribute('key');
            if (! str_starts_with($key, 'ui.')) {
                continue;
            }

            $path = substr($key, 3);
            if ($path === '') {
                continue;
            }

            if (str_starts_with($path, 'brand.')) {
                // Brand settings are sourced from profiles after migration.
                continue;
            }

            /** @var mixed $valueRaw */
            $valueRaw = $row->getAttribute('value');
            if (! is_string($valueRaw)) {
                continue;
            }

            /** @var mixed $typeRaw */
            $typeRaw = $row->getAttribute('type');
            $type = is_string($typeRaw) ? $typeRaw : 'string';

            /** @var mixed $decodedValue */
            $decodedValue = $this->decodeValue($valueRaw, $type);

            Arr::set($config, $path, $decodedValue);
        }

        $profile = $this->activeBrandProfile();
        $config['brand'] = array_merge(
            $this->brandProfileAsConfig($profile),
            ['assets' => $this->brandAssets->storageConfig()]
        );

        /** @var array<string,mixed> $config */
        return $this->sanitizeConfig($config);
    }

    /**
     * @return EloquentCollection<int, BrandProfile>
     */
    public function brandProfiles(): EloquentCollection
    {
        $this->ensureDefaultProfile();

        /** @var EloquentCollection<int, BrandProfile> $profiles */
        $profiles = BrandProfile::query()
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return $profiles;
    }

    public function activeBrandProfile(): BrandProfile
    {
        $this->ensureDefaultProfile();

        /** @var BrandProfile|null $profile */
        $profile = BrandProfile::query()
            ->where('is_active', true)
            ->first();

        if ($profile instanceof BrandProfile) {
            return $profile;
        }

        $default = $this->ensureDefaultProfile();
        $this->activateBrandProfile($default);

        return $default->refresh();
    }

    public function brandProfileById(string $profileId): ?BrandProfile
    {
        return BrandProfile::query()->find($profileId);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function createBrandProfile(string $name, array $input = [], ?BrandProfile $source = null): BrandProfile
    {
        $this->ensureDefaultProfile();

        $base = $source instanceof BrandProfile ? $this->brandProfileAsConfig($source) : $this->brandProfileAsConfig($this->ensureDefaultProfile());
        /** @var array<string,mixed> $merged */
        $merged = array_merge($base, $input);
        $sanitized = $this->sanitizeBrandProfileData($merged);

        /** @var BrandProfile $profile */
        $profile = DB::transaction(function () use ($name, $sanitized): BrandProfile {
            $profile = new BrandProfile([
                'name' => $this->sanitizeProfileName($name),
                'is_default' => false,
                'is_active' => false,
                'is_locked' => false,
                'title_text' => $sanitized['title_text'],
                'favicon_asset_id' => $sanitized['favicon_asset_id'],
                'primary_logo_asset_id' => $sanitized['primary_logo_asset_id'],
                'secondary_logo_asset_id' => $sanitized['secondary_logo_asset_id'],
                'header_logo_asset_id' => $sanitized['header_logo_asset_id'],
                'footer_logo_asset_id' => $sanitized['footer_logo_asset_id'],
                'footer_logo_disabled' => $sanitized['footer_logo_disabled'],
            ]);

            $profile->save();

            return $profile;
        });

        return $profile;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function updateBrandProfile(BrandProfile $profile, array $input): BrandProfile
    {
        if ($profile->getAttribute('is_locked') || $profile->getAttribute('is_default')) {
            throw new BrandProfileLockedException('Default branding profile cannot be modified.');
        }

        $baseline = $this->brandProfileAsConfig($profile);
        /** @var array<string,mixed> $merged */
        $merged = array_merge($baseline, $input);
        $sanitized = $this->sanitizeBrandProfileData($merged);

        $newName = null;
        if (array_key_exists('name', $input) && is_string($input['name'])) {
            $newName = $this->sanitizeProfileName($input['name']);
        }

        DB::transaction(function () use ($profile, $sanitized, $newName): void {
            $profile->fill([
                'title_text' => $sanitized['title_text'],
                'favicon_asset_id' => $sanitized['favicon_asset_id'],
                'primary_logo_asset_id' => $sanitized['primary_logo_asset_id'],
                'secondary_logo_asset_id' => $sanitized['secondary_logo_asset_id'],
                'header_logo_asset_id' => $sanitized['header_logo_asset_id'],
                'footer_logo_asset_id' => $sanitized['footer_logo_asset_id'],
                'footer_logo_disabled' => $sanitized['footer_logo_disabled'],
            ]);

            if ($newName !== null) {
                $profile->setAttribute('name', $newName);
            }

            $profile->save();
        });

        return $profile->refresh();
    }

    public function renameBrandProfile(BrandProfile $profile, string $name): BrandProfile
    {
        if ($profile->getAttribute('is_default')) {
            throw new BrandProfileLockedException('Default branding profile cannot be renamed.');
        }

        $profile->setAttribute('name', $this->sanitizeProfileName($name));
        $profile->save();

        return $profile->refresh();
    }

    public function activateBrandProfile(BrandProfile $profile): void
    {
        if ($profile->getAttribute('is_active')) {
            return;
        }

        DB::transaction(function () use ($profile): void {
            BrandProfile::query()->update(['is_active' => false]);
            $profile->setAttribute('is_active', true);
            $profile->save();
        });
    }

    private function ensureDefaultProfile(): BrandProfile
    {
        /** @var BrandProfile|null $existing */
        $existing = BrandProfile::query()
            ->where('id', self::DEFAULT_PROFILE_ID)
            ->first();

        if ($existing instanceof BrandProfile) {
            return $existing;
        }

        /** @var array<string,mixed> $defaults */
        $defaults = (array) config('ui.defaults.brand', []);

        $profile = new BrandProfile([
            'id' => self::DEFAULT_PROFILE_ID,
            'name' => 'Default',
            'is_default' => true,
            'is_active' => false,
            'is_locked' => true,
            'title_text' => $this->sanitizeTitle($defaults['title_text'] ?? null),
            'favicon_asset_id' => $this->sanitizeAssetId($defaults['favicon_asset_id'] ?? null),
            'primary_logo_asset_id' => $this->sanitizeAssetId($defaults['primary_logo_asset_id'] ?? null),
            'secondary_logo_asset_id' => $this->sanitizeAssetId($defaults['secondary_logo_asset_id'] ?? null),
            'header_logo_asset_id' => $this->sanitizeAssetId($defaults['header_logo_asset_id'] ?? null),
            'footer_logo_asset_id' => $this->sanitizeAssetId($defaults['footer_logo_asset_id'] ?? null),
            'footer_logo_disabled' => $this->toBool($defaults['footer_logo_disabled'] ?? false),
        ]);

        $profile->save();

        return $profile->refresh();
    }

    /**
     * @return array{
     *     title_text:string,
     *     favicon_asset_id:string|null,
     *     primary_logo_asset_id:string|null,
     *     secondary_logo_asset_id:string|null,
     *     header_logo_asset_id:string|null,
     *     footer_logo_asset_id:string|null,
     *     footer_logo_disabled:bool
     * }
     */
    public function brandProfileAsConfig(BrandProfile $profile): array
    {
        return [
            'title_text' => $this->sanitizeTitle($profile->getAttribute('title_text')),
            'favicon_asset_id' => $this->sanitizeAssetId($profile->getAttribute('favicon_asset_id')),
            'primary_logo_asset_id' => $this->sanitizeAssetId($profile->getAttribute('primary_logo_asset_id')),
            'secondary_logo_asset_id' => $this->sanitizeAssetId($profile->getAttribute('secondary_logo_asset_id')),
            'header_logo_asset_id' => $this->sanitizeAssetId($profile->getAttribute('header_logo_asset_id')),
            'footer_logo_asset_id' => $this->sanitizeAssetId($profile->getAttribute('footer_logo_asset_id')),
            'footer_logo_disabled' => $this->toBool($profile->getAttribute('footer_logo_disabled')),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *     title_text:string,
     *     favicon_asset_id:string|null,
     *     primary_logo_asset_id:string|null,
     *     secondary_logo_asset_id:string|null,
     *     header_logo_asset_id:string|null,
     *     footer_logo_asset_id:string|null,
     *     footer_logo_disabled:bool
     * }
     */
    public function sanitizeBrandProfileData(array $input): array
    {
        /** @var array<string,mixed> $sanitized */
        $sanitized = $this->sanitizeConfig(['brand' => $input]);

        /** @var array{
         *     title_text:string,
         *     favicon_asset_id:string|null,
         *     primary_logo_asset_id:string|null,
         *     secondary_logo_asset_id:string|null,
         *     header_logo_asset_id:string|null,
         *     footer_logo_asset_id:string|null,
         *     footer_logo_disabled:bool,
         *     assets: array{filesystem_path:string}
         * } $brand
         */
        $brand = $sanitized['brand'];

        return [
            'title_text' => $brand['title_text'],
            'favicon_asset_id' => $brand['favicon_asset_id'],
            'primary_logo_asset_id' => $brand['primary_logo_asset_id'],
            'secondary_logo_asset_id' => $brand['secondary_logo_asset_id'],
            'header_logo_asset_id' => $brand['header_logo_asset_id'],
            'footer_logo_asset_id' => $brand['footer_logo_asset_id'],
            'footer_logo_disabled' => $brand['footer_logo_disabled'],
        ];
    }

    private function sanitizeProfileName(?string $name): string
    {
        $trimmed = trim((string) $name);
        if ($trimmed === '') {
            $trimmed = 'Untitled Profile';
        }

        if (mb_strlen($trimmed) > 120) {
            $trimmed = mb_substr($trimmed, 0, 120);
        }

        return $trimmed;
    }

    /**
     * @param array{
     *     title_text:string,
     *     favicon_asset_id:string|null,
     *     primary_logo_asset_id:string|null,
     *     secondary_logo_asset_id:string|null,
     *     header_logo_asset_id:string|null,
     *     footer_logo_asset_id:string|null,
     *     footer_logo_disabled:bool
     * } $before
     * @param array{
     *     title_text:string,
     *     favicon_asset_id:string|null,
     *     primary_logo_asset_id:string|null,
     *     secondary_logo_asset_id:string|null,
     *     header_logo_asset_id:string|null,
     *     footer_logo_asset_id:string|null,
     *     footer_logo_disabled:bool
     * } $after
     * @return list<array{key:string, old:mixed, new:mixed, action:string}>
     */
    private function diffBrandChanges(array $before, array $after, string $prefix): array
    {
        $defaults = $this->sanitizeBrandProfileData([]);
        $fields = [
            'title_text',
            'favicon_asset_id',
            'primary_logo_asset_id',
            'secondary_logo_asset_id',
            'header_logo_asset_id',
            'footer_logo_asset_id',
            'footer_logo_disabled',
        ];

        $changes = [];

        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ($this->valuesEqual($old, $new)) {
                continue;
            }

            $default = $defaults[$field] ?? null;
            $action = $this->valuesEqual($new, $default) ? 'unset' : ($old === null ? 'set' : 'update');

            $changes[] = [
                'key' => sprintf('%s.%s', $prefix, $field),
                'old' => $old,
                'new' => $new,
                'action' => $action,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *     config: array{
     *         theme: array{
     *             default: string,
     *             allow_user_override: bool,
     *             force_global: bool,
     *             overrides: array<string,string|null>,
     *             designer: array{storage: string, filesystem_path: string}
     *         },
     *         nav: array{sidebar: array{default_order: array<int,string>}},
     *         brand: array{
     *             title_text: string,
     *             favicon_asset_id: string|null,
     *             primary_logo_asset_id: string|null,
     *             secondary_logo_asset_id: string|null,
     *             header_logo_asset_id: string|null,
     *             footer_logo_asset_id: string|null,
     *             footer_logo_disabled: bool,
     *             assets: array{filesystem_path: string}
     *         }
     *     },
     *     changes: list<array{key:string, old:mixed, new:mixed, action:string}>
     * }
     */
    public function apply(array $input, ?int $actorId = null): array
    {
        $current = $this->currentConfig();

        $combined = $current;

        /** @var array<string,mixed>|null $themeInput */
        $themeInput = isset($input['theme']) && is_array($input['theme']) ? $input['theme'] : null;
        if ($themeInput !== null) {
            $combined['theme'] = array_merge($current['theme'], $themeInput);
        }

        /** @var array<string,mixed>|null $navInput */
        $navInput = data_get($input, 'nav.sidebar');
        if (is_array($navInput)) {
            $combined['nav']['sidebar'] = array_merge($current['nav']['sidebar'], $navInput);
        }

        /** @var array<string,mixed> $currentBrand */
        $currentBrand = $current['brand'];
        $combined['brand'] = $currentBrand;
        /** @var array{filesystem_path:string} $currentBrandAssets */
        $currentBrandAssets = $currentBrand['assets'];
        $combined['brand']['assets'] = $currentBrandAssets;
        $brandChanges = [];

        /** @var array<string,mixed>|null $brandInput */
        $brandInput = isset($input['brand']) && is_array($input['brand']) ? $input['brand'] : null;
        /** @var array<string,mixed>|null $assetsInput */
        $assetsInput = null;
        if ($brandInput !== null) {
            $profileId = null;
            if (array_key_exists('profile_id', $brandInput) && is_string($brandInput['profile_id'])) {
                $profileId = trim($brandInput['profile_id']);
                unset($brandInput['profile_id']);
            }

            if (isset($brandInput['assets']) && is_array($brandInput['assets'])) {
                /** @var array<string,mixed> $assetsPayload */
                $assetsPayload = $brandInput['assets'];
                $assetsInput = $assetsPayload;
                unset($brandInput['assets']);
            }

            /** @var BrandProfile|null $profile */
            $profile = $profileId !== null && $profileId !== ''
                ? $this->brandProfileById($profileId)
                : $this->activeBrandProfile();

            if (! $profile instanceof BrandProfile) {
                throw new BrandProfileNotFoundException('Brand profile not found.');
            }

            if ($brandInput !== []) {
                $beforeProfileConfig = $this->brandProfileAsConfig($profile);
                $updatedProfile = $this->updateBrandProfile($profile, $brandInput);
                /** @var array{filesystem_path:string} $brandAssetsBefore */
                $brandAssetsBefore = $combined['brand']['assets'];

                if ($updatedProfile->getAttribute('is_active')) {
                    $combined['brand'] = array_merge(
                        $this->brandProfileAsConfig($updatedProfile),
                        ['assets' => $brandAssetsBefore]
                    );
                    $brandChanges = $this->diffBrandChanges(
                        $beforeProfileConfig,
                        $this->brandProfileAsConfig($updatedProfile),
                        'ui.brand'
                    );
                } else {
                    $profileIdAttr = $updatedProfile->getAttribute('id');
                    if (! is_string($profileIdAttr)) {
                        throw new \UnexpectedValueException('Brand profile id must be a string.');
                    }
                    $brandChanges = $this->diffBrandChanges(
                        $beforeProfileConfig,
                        $this->brandProfileAsConfig($updatedProfile),
                        sprintf('ui.brand_profiles.%s', $profileIdAttr)
                    );
                }
            }
        }

        if ($assetsInput !== null) {
            /** @var array{filesystem_path:string} $existingAssets */
            $existingAssets = $combined['brand']['assets'];
            $combined['brand']['assets'] = $this->sanitizeBrandAssetsConfig(
                $existingAssets,
                $assetsInput
            );
        }

        $sanitized = $this->sanitizeConfig($combined);
        $flatCurrent = $this->flattenConfig($current);
        $flatSanitized = $this->flattenConfig($sanitized);
        $flatDefaults = $this->flattenConfig($this->sanitizeConfig([]));

        $now = now('UTC')->toDateTimeString();

        /** @var list<array<string,mixed>> $upserts */
        $upserts = [];
        /** @var list<string> $deletes */
        $deletes = [];
        /** @var list<array{key:string, old:mixed, new:mixed, action:string}> $changes */
        $changes = [];

        /** @var mixed $value */
        foreach ($flatSanitized as $key => $value) {
            if (! in_array($key, self::STORAGE_KEYS, true)) {
                continue;
            }

            /** @var mixed $before */
            $before = $flatCurrent[$key] ?? ($flatDefaults[$key] ?? null);
            if ($this->valuesEqual($before, $value)) {
                continue;
            }

            /** @var mixed $default */
            $default = $flatDefaults[$key] ?? null;

            if ($this->valuesEqual($value, $default)) {
                $deletes[] = $key;
                $changes[] = [
                    'key' => $key,
                    'old' => $before,
                    'new' => $value,
                    'action' => 'unset',
                ];

                continue;
            }

            [$storeValue, $storeType] = $this->encodeForStorage($value);

            $upserts[] = [
                'key' => $key,
                'value' => $storeValue,
                'type' => $storeType,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $changes[] = [
                'key' => $key,
                'old' => $before,
                'new' => $value,
                'action' => $before === null ? 'set' : 'update',
            ];
        }

        if ($upserts !== []) {
            DB::table('ui_settings')->upsert(
                $upserts,
                ['key'],
                ['value', 'type', 'updated_by', 'updated_at']
            );
        }

        if ($deletes !== []) {
            UiSetting::query()
                ->whereIn('key', $deletes)
                ->delete();
        }

        return [
            'config' => $this->currentConfig(),
            'changes' => array_merge($changes, $brandChanges),
        ];
    }

    /**
     * @param array{
     *     theme: array{
     *         default: string,
     *         allow_user_override: bool,
     *         force_global: bool,
     *         overrides: array<string,string|null>,
     *         designer: array{storage: string, filesystem_path: string}
     *     },
     *     nav: array{sidebar: array{default_order: array<int,string>}},
     *     brand: array{
     *         title_text: string,
     *         favicon_asset_id: string|null,
     *         primary_logo_asset_id: string|null,
     *         secondary_logo_asset_id: string|null,
     *         header_logo_asset_id: string|null,
     *         footer_logo_asset_id: string|null,
     *         footer_logo_disabled: bool,
     *         assets: array{filesystem_path: string}
     *     }
     * } $config
     */
    public function etagFor(array $config): string
    {
        /** @var mixed $normalized */
        $normalized = $this->normalizeForHash($config);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode UI settings for ETag');
        }

        return sprintf('W/"ui:%s"', hash('sha256', $encoded));
    }

    public function clearBrandAssetReference(string $assetId): void
    {
        BrandProfile::query()
            ->where(function (Builder $query) use ($assetId): void {
                $query->where('favicon_asset_id', $assetId)
                    ->orWhere('primary_logo_asset_id', $assetId)
                    ->orWhere('secondary_logo_asset_id', $assetId)
                    ->orWhere('header_logo_asset_id', $assetId)
                    ->orWhere('footer_logo_asset_id', $assetId);
            })
            ->get()
            ->each(function (BrandProfile $profile) use ($assetId): void {
                $updated = false;

                foreach ([
                    'favicon_asset_id',
                    'primary_logo_asset_id',
                    'secondary_logo_asset_id',
                    'header_logo_asset_id',
                    'footer_logo_asset_id',
                ] as $column) {
                    if ($profile->getAttribute($column) === $assetId) {
                        $profile->setAttribute($column, null);
                        $updated = true;
                    }
                }

                if ($updated) {
                    $profile->save();
                }
            });
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{
     *     theme: array{default: string, allow_user_override: bool, force_global: bool, overrides: array<string,string|null>, designer: array{storage: string, filesystem_path: string}},
     *     nav: array{sidebar: array{default_order: array<int,string>}},
     *     brand: array{
     *         title_text: string,
     *         favicon_asset_id: string|null,
     *         primary_logo_asset_id: string|null,
     *         secondary_logo_asset_id: string|null,
     *         header_logo_asset_id: string|null,
     *         footer_logo_asset_id: string|null,
     *         footer_logo_disabled: bool,
     *         assets: array{filesystem_path: string}
     *     }
     * }
     */
    private function sanitizeConfig(array $config): array
    {
        /** @var array<string,mixed> $defaults */
        $defaults = (array) config('ui.defaults', []);

        $fallbackTheme = $this->defaultThemeSlug();

        /** @var array<string,mixed> $themeDefaults */
        $themeDefaults = is_array($defaults['theme'] ?? null) ? $defaults['theme'] : [];
        /** @var mixed $themeOverridesRaw */
        $themeOverridesRaw = $themeDefaults['overrides'] ?? [];
        $themeOverridesBase = is_array($themeOverridesRaw) ? $themeOverridesRaw : [];

        /** @var array<string,mixed> $designerDefaultsRaw */
        $designerDefaultsRaw = is_array($themeDefaults['designer'] ?? null) ? $themeDefaults['designer'] : [];
        $designer = $this->sanitizeDesignerConfig($designerDefaultsRaw, []);

        $theme = [
            'default' => $this->sanitizeThemeSlug($themeDefaults['default'] ?? null, $fallbackTheme),
            'allow_user_override' => $this->toBool($themeDefaults['allow_user_override'] ?? true),
            'force_global' => $this->toBool($themeDefaults['force_global'] ?? false),
            'overrides' => $this->sanitizeOverrides($themeOverridesBase),
            'designer' => $designer,
        ];

        if (isset($config['theme']) && is_array($config['theme'])) {
            $themeInput = $config['theme'];

            if (array_key_exists('default', $themeInput)) {
                $theme['default'] = $this->sanitizeThemeSlug($themeInput['default'], $theme['default']);
            }

            if (array_key_exists('allow_user_override', $themeInput)) {
                $theme['allow_user_override'] = $this->toBool($themeInput['allow_user_override']);
            }

            if (array_key_exists('force_global', $themeInput)) {
                $theme['force_global'] = $this->toBool($themeInput['force_global']);
            }

            if (isset($themeInput['overrides']) && is_array($themeInput['overrides'])) {
                $theme['overrides'] = $this->sanitizeOverrides($themeInput['overrides']);
            }

            if (isset($themeInput['designer']) && is_array($themeInput['designer'])) {
                /** @var array<string,mixed> $designerOverride */
                $designerOverride = $themeInput['designer'];
                /** @var array<string,mixed> $designerBase */
                $designerBase = $designer;
                $theme['designer'] = $this->sanitizeDesignerConfig(
                    $designerBase,
                    $designerOverride
                );
            }
        }

        /** @var array<string,mixed> $navDefaults */
        $navDefaults = (array) ($defaults['nav'] ?? []);
        /** @var array<string,mixed> $sidebarDefaults */
        $sidebarDefaults = (array) ($navDefaults['sidebar'] ?? []);
        $sidebar = [
            'default_order' => $this->sanitizeSidebarOrder($sidebarDefaults['default_order'] ?? []),
        ];

        if (isset($config['nav']) && is_array($config['nav'])) {
            $navInput = $config['nav'];
            if (isset($navInput['sidebar']) && is_array($navInput['sidebar'])) {
                $sidebarInput = $navInput['sidebar'];
                if (array_key_exists('default_order', $sidebarInput)) {
                    $sidebar['default_order'] = $this->sanitizeSidebarOrder($sidebarInput['default_order']);
                }
            }
        }

        /** @var array<string,mixed> $brandDefaults */
        $brandDefaults = (array) ($defaults['brand'] ?? []);
        /** @var array<string,mixed> $brandAssetsDefaults */
        $brandAssetsDefaults = is_array($brandDefaults['assets'] ?? null) ? $brandDefaults['assets'] : [];
        $brand = [
            'title_text' => $this->sanitizeTitle($brandDefaults['title_text'] ?? null),
            'favicon_asset_id' => $this->sanitizeAssetId($brandDefaults['favicon_asset_id'] ?? null),
            'primary_logo_asset_id' => $this->sanitizeAssetId($brandDefaults['primary_logo_asset_id'] ?? null),
            'secondary_logo_asset_id' => $this->sanitizeAssetId($brandDefaults['secondary_logo_asset_id'] ?? null),
            'header_logo_asset_id' => $this->sanitizeAssetId($brandDefaults['header_logo_asset_id'] ?? null),
            'footer_logo_asset_id' => $this->sanitizeAssetId($brandDefaults['footer_logo_asset_id'] ?? null),
            'footer_logo_disabled' => $this->toBool($brandDefaults['footer_logo_disabled'] ?? false),
            'assets' => $this->sanitizeBrandAssetsConfig($brandAssetsDefaults, []),
        ];

        if (isset($config['brand']) && is_array($config['brand'])) {
            $brandInput = $config['brand'];

            if (array_key_exists('title_text', $brandInput)) {
                $brand['title_text'] = $this->sanitizeTitle($brandInput['title_text']);
            }

            foreach (['favicon', 'primary_logo', 'secondary_logo', 'header_logo', 'footer_logo'] as $slot) {
                $key = $slot.'_asset_id';
                if (array_key_exists($key, $brandInput)) {
                    $brand[$key] = $this->sanitizeAssetId($brandInput[$key]);
                }
            }

            if (array_key_exists('footer_logo_disabled', $brandInput)) {
                $brand['footer_logo_disabled'] = $this->toBool($brandInput['footer_logo_disabled']);
            }

            if (isset($brandInput['assets']) && is_array($brandInput['assets'])) {
                /** @var array<string,mixed> $brandAssetsOverride */
                $brandAssetsOverride = $brandInput['assets'];
                /** @var array<string,mixed> $brandAssetsState */
                $brandAssetsState = $brand['assets'];
                $brand['assets'] = $this->sanitizeBrandAssetsConfig(
                    $brandAssetsState,
                    $brandAssetsOverride
                );
            }
        }

        $primaryLogo = $brand['primary_logo_asset_id'];
        if ($brand['favicon_asset_id'] === null && $primaryLogo !== null) {
            $brand['favicon_asset_id'] = $primaryLogo;
        }

        if ($brand['footer_logo_disabled'] === false && $brand['footer_logo_asset_id'] === null && $primaryLogo !== null) {
            $brand['footer_logo_asset_id'] = $primaryLogo;
        }

        return [
            'theme' => $theme,
            'nav' => ['sidebar' => $sidebar],
            'brand' => $brand,
        ];
    }

    /**
     * @param  array<int|string,mixed>  $input
     * @return array<string,string|null>
     */
    private function sanitizeOverrides(array $input): array
    {
        /** @var array<int,string> $allowed */
        $allowed = (array) config('ui.overrides.allowed_keys', []);
        $allowedMap = array_fill_keys($allowed, true);

        /** @var array<string,string|null> $result */
        $result = [];

        foreach ($allowed as $token) {
            $result[$token] = $this->defaultOverride($token);
        }

        $shadowPresets = $this->presetOptions((array) config('ui.overrides.shadow_presets', []), 'default');
        $spacingPresets = $this->presetOptions((array) config('ui.overrides.spacing_presets', []), 'default');
        $typeScalePresets = $this->presetOptions((array) config('ui.overrides.type_scale_presets', []), 'medium');
        $motionPresets = $this->presetOptions((array) config('ui.overrides.motion_presets', []), 'full');

        /** @var mixed $value */
        foreach ($input as $token => $value) {
            if (! is_string($token) || ! isset($allowedMap[$token])) {
                continue;
            }

            if (str_starts_with($token, 'color.')) {
                if (is_string($value) && trim($value) !== '') {
                    $result[$token] = $value;
                }

                continue;
            }

            if ($token === 'shadow') {
                $result[$token] = $this->sanitizePreset($value, $shadowPresets);

                continue;
            }

            if ($token === 'spacing') {
                $result[$token] = $this->sanitizePreset($value, $spacingPresets);

                continue;
            }

            if ($token === 'typeScale') {
                $result[$token] = $this->sanitizePreset($value, $typeScalePresets);

                continue;
            }

            if ($token === 'motion') {
                $result[$token] = $this->sanitizePreset($value, $motionPresets);

                continue;
            }
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $override
     * @return array{storage:string, filesystem_path:string}
     */
    private function sanitizeDesignerConfig(array $base, array $override): array
    {
        $storage = $this->defaultDesignerStorage($base['storage'] ?? null);
        $path = $this->defaultDesignerPath($base['filesystem_path'] ?? null);

        if (array_key_exists('storage', $override)) {
            $storage = $this->sanitizeDesignerStorage($override['storage'], $storage);
        }

        if (array_key_exists('filesystem_path', $override)) {
            $path = $this->sanitizeDesignerPath($override['filesystem_path'], $path);
        }

        return [
            'storage' => $storage,
            'filesystem_path' => $path,
        ];
    }

    private function defaultDesignerStorage(mixed $value): string
    {
        /** @var mixed $fallbackRaw */
        $fallbackRaw = config('ui.defaults.theme.designer.storage', 'filesystem');
        $fallback = is_string($fallbackRaw) ? $fallbackRaw : 'filesystem';

        return $this->sanitizeDesignerStorage($value, $fallback);
    }

    private function defaultDesignerPath(mixed $value): string
    {
        /** @var mixed $fallbackRaw */
        $fallbackRaw = config('ui.defaults.theme.designer.filesystem_path', '/opt/phpgrc/shared/themes');
        $fallback = is_string($fallbackRaw) ? $fallbackRaw : '/opt/phpgrc/shared/themes';

        return $this->sanitizeDesignerPath($value, $fallback);
    }

    private function sanitizeDesignerStorage(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $this->normalizeStorageToken($fallback);
        }

        return $this->normalizeStorageToken($value);
    }

    private function normalizeStorageToken(string $value): string
    {
        $token = strtolower(trim($value));

        return in_array($token, ['browser', 'filesystem'], true) ? $token : 'filesystem';
    }

    private function sanitizeDesignerPath(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $this->normalizeDesignerPath($fallback);
        }

        return $this->normalizeDesignerPath($value);
    }

    private function normalizeDesignerPath(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            /** @var mixed $fallbackRaw */
            $fallbackRaw = config('ui.defaults.theme.designer.filesystem_path', '/opt/phpgrc/shared/themes');
            $fallback = is_string($fallbackRaw) ? trim($fallbackRaw) : '';

            return $fallback !== '' ? $fallback : '/opt/phpgrc/shared/themes';
        }

        if (! str_starts_with($trimmed, '/')) {
            return '/'.ltrim($trimmed, '/');
        }

        return $trimmed;
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $override
     * @return array{filesystem_path:string}
     */
    private function sanitizeBrandAssetsConfig(array $base, array $override): array
    {
        $defaultPath = $this->brandAssets->defaultPath();
        $path = $defaultPath;

        if (array_key_exists('filesystem_path', $base)) {
            $path = $this->brandAssets->sanitizePath($base['filesystem_path'], $path);
        }

        if (array_key_exists('filesystem_path', $override)) {
            $path = $this->brandAssets->sanitizePath($override['filesystem_path'], $path);
        }

        return [
            'filesystem_path' => $this->brandAssets->sanitizePath($path, $defaultPath),
        ];
    }

    private function defaultOverride(string $token): ?string
    {
        /** @var array<string,mixed> $defaults */
        $defaults = (array) config('ui.defaults.theme.overrides', []);

        /** @var mixed $value */
        $value = $defaults[$token] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<int,string>  $allowed
     */
    private function sanitizePreset(mixed $value, array $allowed): string
    {
        if (! is_string($value)) {
            return $allowed[0] ?? '';
        }
        $token = trim($value);
        if ($token === '') {
            return $allowed[0] ?? '';
        }

        foreach ($allowed as $option) {
            if ($token === $option) {
                return $token;
            }
        }

        return $allowed[0] ?? '';
    }

    private function sanitizeTitle(mixed $value): string
    {
        /** @var mixed $defaultRaw */
        $defaultRaw = config('ui.defaults.brand.title_text');
        $defaultTitle = is_string($defaultRaw) ? trim($defaultRaw) : 'phpGRC — Dashboard';
        if ($defaultTitle === '') {
            $defaultTitle = 'phpGRC — Dashboard';
        }

        if (! is_string($value)) {
            return $defaultTitle;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return $defaultTitle;
        }

        if (mb_strlen($trimmed) > 120) {
            return mb_substr($trimmed, 0, 120);
        }

        return $trimmed;
    }

    private function sanitizeAssetId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param  array<int|string,mixed>  $raw
     * @return array<int,string>
     */
    private function presetOptions(array $raw, string $fallback): array
    {
        $options = [];
        /** @var mixed $item */
        foreach ($raw as $item) {
            if (is_string($item)) {
                $token = trim($item);
                if ($token !== '') {
                    $options[] = $token;
                }
            }
        }

        if ($options === []) {
            $options[] = $fallback;
        }

        return $options;
    }

    /**
     * @return array<int,string>
     */
    private function sanitizeSidebarOrder(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $order = [];
        /** @var mixed $item */
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $token = trim($item);
            if ($token !== '') {
                $order[] = $token;
            }
        }

        return $order;
    }

    private function defaultThemeSlug(): string
    {
        /** @var array<string,string>|null $defaults */
        $defaults = config('ui.manifest.defaults');

        $fallback = 'slate';
        if (is_array($defaults)) {
            $candidate = $defaults['dark'] ?? null;
            if (is_string($candidate)) {
                $trimmed = trim($candidate);
                if ($trimmed !== '') {
                    $fallback = $trimmed;
                }
            }
        }

        return $fallback;
    }

    private function sanitizeThemeSlug(mixed $value, string $fallback): string
    {
        if (is_string($value)) {
            $slug = trim($value);
            if ($slug !== '' && $this->manifestHasTheme($slug)) {
                return $slug;
            }
        }

        return $fallback;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $token = strtolower(trim($value));

            return in_array($token, ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    private function manifestHasTheme(string $slug): bool
    {
        return $this->themePacks->hasTheme($slug);
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function flattenConfig(array $config): array
    {
        /** @var array<string,mixed> $theme */
        $theme = (array) ($config['theme'] ?? []);
        /** @var array<string,mixed> $nav */
        $nav = (array) ($config['nav'] ?? []);
        /** @var array<string,mixed> $sidebar */
        $sidebar = (array) ($nav['sidebar'] ?? []);
        /** @var array<string,mixed> $brand */
        $brand = (array) ($config['brand'] ?? []);
        /** @var array{filesystem_path:string}|null $brandAssets */
        $brandAssets = is_array($brand['assets'] ?? null) ? $brand['assets'] : null;
        if ($brandAssets === null) {
            $brandAssets = $this->sanitizeBrandAssetsConfig([], []);
        }

        /** @var array<string,mixed> $designerInput */
        $designerInput = is_array($theme['designer'] ?? null) ? $theme['designer'] : [];

        /** @var array<string,mixed> $designerBase */
        $designerBase = [
            'storage' => $this->defaultDesignerStorage(null),
            'filesystem_path' => $this->defaultDesignerPath(null),
        ];

        /** @var array<string,mixed> $designerOverride */
        $designerOverride = $designerInput;

        $designer = $this->sanitizeDesignerConfig($designerBase, $designerOverride);

        return [
            'ui.theme.default' => $theme['default'] ?? null,
            'ui.theme.allow_user_override' => $theme['allow_user_override'] ?? null,
            'ui.theme.force_global' => $theme['force_global'] ?? null,
            'ui.theme.overrides' => $theme['overrides'] ?? [],
            'ui.theme.designer.storage' => $designer['storage'],
            'ui.theme.designer.filesystem_path' => $designer['filesystem_path'],
            'ui.nav.sidebar.default_order' => $sidebar['default_order'] ?? [],
            'ui.brand.title_text' => $brand['title_text'] ?? null,
            'ui.brand.favicon_asset_id' => $brand['favicon_asset_id'] ?? null,
            'ui.brand.primary_logo_asset_id' => $brand['primary_logo_asset_id'] ?? null,
            'ui.brand.secondary_logo_asset_id' => $brand['secondary_logo_asset_id'] ?? null,
            'ui.brand.header_logo_asset_id' => $brand['header_logo_asset_id'] ?? null,
            'ui.brand.footer_logo_asset_id' => $brand['footer_logo_asset_id'] ?? null,
            'ui.brand.footer_logo_disabled' => $brand['footer_logo_disabled'] ?? null,
            'ui.brand.assets.filesystem_path' => $brandAssets['filesystem_path'],
        ];
    }

    private function decodeValue(string $value, string $type): mixed
    {
        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => $this->decodeJson($value),
            default => $value,
        };
    }

    private function decodeJson(string $value): mixed
    {
        /** @var mixed $decoded */
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function encodeForStorage(mixed $value): array
    {
        if (is_bool($value)) {
            return [$value ? '1' : '0', 'bool'];
        }
        if (is_int($value)) {
            return [(string) $value, 'int'];
        }
        if (is_float($value)) {
            return [rtrim(rtrim(sprintf('%.14F', $value), '0'), '.'), 'float'];
        }
        if (is_string($value)) {
            return [$value, 'string'];
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode UI setting value.');
        }

        return [$encoded, 'json'];
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($value === []) {
                return [];
            }

            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
            }

            $assoc = $value;
            ksort($assoc);

            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $assoc);
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return $value;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        return $this->normalizeForHash($a) === $this->normalizeForHash($b);
    }
}
