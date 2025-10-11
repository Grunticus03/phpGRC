<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\UiSetting;
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
        'ui.nav.sidebar.default_order',
        'ui.brand.title_text',
        'ui.brand.favicon_asset_id',
        'ui.brand.primary_logo_asset_id',
        'ui.brand.secondary_logo_asset_id',
        'ui.brand.header_logo_asset_id',
        'ui.brand.footer_logo_asset_id',
        'ui.brand.footer_logo_disabled',
    ];

    /**
     * @return array{
     *     theme: array{default: string, allow_user_override: bool, force_global: bool, overrides: array<string,string|null>},
     *     nav: array{sidebar: array{default_order: array<int,string>}},
     *     brand: array{
     *         title_text: string,
     *         favicon_asset_id: string|null,
     *         primary_logo_asset_id: string|null,
     *         secondary_logo_asset_id: string|null,
     *         header_logo_asset_id: string|null,
     *         footer_logo_asset_id: string|null,
     *         footer_logo_disabled: bool
     *     }
     * }
     */
    public function currentConfig(): array
    {
        /** @var array<string,mixed> $config */
        $config = $this->sanitizeConfig([]);

        /** @var Collection<int, UiSetting> $rows */
        $rows = UiSetting::query()
            ->orderBy('key')
            ->get(['key', 'value', 'type']);

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

        /** @var array<string,mixed> $config */
        return $this->sanitizeConfig($config);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *     config: array{
     *         theme: array{default: string, allow_user_override: bool, force_global: bool, overrides: array<string,string|null>},
     *         nav: array{sidebar: array{default_order: array<int,string>}},
     *         brand: array{
     *             title_text: string,
     *             favicon_asset_id: string|null,
     *             primary_logo_asset_id: string|null,
     *             secondary_logo_asset_id: string|null,
     *             header_logo_asset_id: string|null,
     *             footer_logo_asset_id: string|null,
     *             footer_logo_disabled: bool
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

        /** @var array<string,mixed>|null $brandInput */
        $brandInput = isset($input['brand']) && is_array($input['brand']) ? $input['brand'] : null;
        if ($brandInput !== null) {
            $combined['brand'] = array_merge($current['brand'], $brandInput);
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
                    'action' => 'delete',
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
                'action' => 'upsert',
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
            'changes' => $changes,
        ];
    }

    /**
     * @param array{
     *     theme: array{default: string, allow_user_override: bool, force_global: bool, overrides: array<string,string|null>},
     *     nav: array{sidebar: array{default_order: array<int,string>}},
     *     brand: array{
     *         title_text: string,
     *         favicon_asset_id: string|null,
     *         primary_logo_asset_id: string|null,
     *         secondary_logo_asset_id: string|null,
     *         header_logo_asset_id: string|null,
     *         footer_logo_asset_id: string|null,
     *         footer_logo_disabled: bool
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
        $brandKeys = [
            'ui.brand.favicon_asset_id',
            'ui.brand.primary_logo_asset_id',
            'ui.brand.secondary_logo_asset_id',
            'ui.brand.header_logo_asset_id',
            'ui.brand.footer_logo_asset_id',
        ];

        UiSetting::query()
            ->whereIn('key', $brandKeys)
            ->get()
            ->each(function (UiSetting $row) use ($assetId): void {
                $valueRaw = $row->getAttribute('value');
                $typeRaw = $row->getAttribute('type');
                if (! is_string($valueRaw) || ! is_string($typeRaw)) {
                    return;
                }

                /** @var mixed $decodedValue */
                $decodedValue = $this->decodeValue($valueRaw, $typeRaw);
                if ($decodedValue === $assetId) {
                    $row->setAttribute('value', 'null');
                    $row->setAttribute('type', 'json');
                    $row->save();
                }
            });
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{
     *     theme: array{default: string, allow_user_override: bool, force_global: bool, overrides: array<string,string|null>},
     *     nav: array{sidebar: array{default_order: array<int,string>}},
     *     brand: array{
     *         title_text: string,
     *         favicon_asset_id: string|null,
     *         primary_logo_asset_id: string|null,
     *         secondary_logo_asset_id: string|null,
     *         header_logo_asset_id: string|null,
     *         footer_logo_asset_id: string|null,
     *         footer_logo_disabled: bool
     *     }
     * }
     */
    private function sanitizeConfig(array $config): array
    {
        /** @var array<string,mixed> $defaults */
        $defaults = (array) config('ui.defaults', []);

        $fallbackTheme = $this->defaultThemeSlug();

        /** @var array<string,mixed> $themeDefaults */
        $themeDefaults = (array) ($defaults['theme'] ?? []);
        /** @var mixed $themeOverridesRaw */
        $themeOverridesRaw = $themeDefaults['overrides'] ?? [];
        $themeOverridesBase = is_array($themeOverridesRaw) ? $themeOverridesRaw : [];

        $theme = [
            'default' => $this->sanitizeThemeSlug($themeDefaults['default'] ?? null, $fallbackTheme),
            'allow_user_override' => $this->toBool($themeDefaults['allow_user_override'] ?? true),
            'force_global' => $this->toBool($themeDefaults['force_global'] ?? false),
            'overrides' => $this->sanitizeOverrides($themeOverridesBase),
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
        $brand = [
            'title_text' => $this->sanitizeTitle($brandDefaults['title_text'] ?? null),
            'favicon_asset_id' => $this->sanitizeAssetId($brandDefaults['favicon_asset_id'] ?? null),
            'primary_logo_asset_id' => $this->sanitizeAssetId($brandDefaults['primary_logo_asset_id'] ?? null),
            'secondary_logo_asset_id' => $this->sanitizeAssetId($brandDefaults['secondary_logo_asset_id'] ?? null),
            'header_logo_asset_id' => $this->sanitizeAssetId($brandDefaults['header_logo_asset_id'] ?? null),
            'footer_logo_asset_id' => $this->sanitizeAssetId($brandDefaults['footer_logo_asset_id'] ?? null),
            'footer_logo_disabled' => $this->toBool($brandDefaults['footer_logo_disabled'] ?? false),
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
        /** @var array<string,mixed> $manifest */
        $manifest = (array) config('ui.manifest', []);
        /** @var array<int,mixed> $themes */
        $themes = (array) ($manifest['themes'] ?? []);
        foreach ($themes as $theme) {
            if (! is_array($theme)) {
                continue;
            }
            $manifestSlug = isset($theme['slug']) && is_string($theme['slug']) ? trim($theme['slug']) : null;
            if ($manifestSlug !== null && $manifestSlug === $slug) {
                return true;
            }
        }

        /** @var array<int,mixed> $packs */
        $packs = (array) ($manifest['packs'] ?? []);
        foreach ($packs as $pack) {
            if (! is_array($pack)) {
                continue;
            }
            $manifestSlug = isset($pack['slug']) && is_string($pack['slug']) ? trim($pack['slug']) : null;
            if ($manifestSlug !== null && $manifestSlug === $slug) {
                return true;
            }
        }

        return false;
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

        return [
            'ui.theme.default' => $theme['default'] ?? null,
            'ui.theme.allow_user_override' => $theme['allow_user_override'] ?? null,
            'ui.theme.force_global' => $theme['force_global'] ?? null,
            'ui.theme.overrides' => $theme['overrides'] ?? [],
            'ui.nav.sidebar.default_order' => $sidebar['default_order'] ?? [],
            'ui.brand.title_text' => $brand['title_text'] ?? null,
            'ui.brand.favicon_asset_id' => $brand['favicon_asset_id'] ?? null,
            'ui.brand.primary_logo_asset_id' => $brand['primary_logo_asset_id'] ?? null,
            'ui.brand.secondary_logo_asset_id' => $brand['secondary_logo_asset_id'] ?? null,
            'ui.brand.header_logo_asset_id' => $brand['header_logo_asset_id'] ?? null,
            'ui.brand.footer_logo_asset_id' => $brand['footer_logo_asset_id'] ?? null,
            'ui.brand.footer_logo_disabled' => $brand['footer_logo_disabled'] ?? null,
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
