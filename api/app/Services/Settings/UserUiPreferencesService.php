<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\UserUiPreference;

/**
 * @phpstan-type ThemeOverrides array<string,string|null>
 * @phpstan-type SidebarPrefs array{collapsed: bool, width: int, order: array<int,string>}
 * @phpstan-type UserPrefs array{
 *     theme: string|null,
 *     mode: string|null,
 *     overrides: ThemeOverrides,
 *     sidebar: SidebarPrefs
 * }
 */
final class UserUiPreferencesService
{
    private const MIN_SIDEBAR_WIDTH = 50;

    private const MAX_SIDEBAR_WIDTH = 480;

    /** @return UserPrefs */
    public function get(int $userId): array
    {
        $defaults = $this->defaultPrefs();

        /** @var UserUiPreference|null $record */
        $record = UserUiPreference::query()->find($userId);
        if ($record === null) {
            return $defaults;
        }

        /** @var UserPrefs $prefs */
        $prefs = $defaults;

        /** @var mixed $theme */
        $theme = $record->getAttribute('theme');
        if (is_string($theme) && $theme !== '') {
            $prefs['theme'] = $theme;
        }

        /** @var mixed $mode */
        $mode = $record->getAttribute('mode');
        if (is_string($mode) && in_array($mode, ['light', 'dark'], true)) {
            $prefs['mode'] = $mode;
        }

        /** @var mixed $overridesRaw */
        $overridesRaw = $record->getAttribute('overrides');
        if (is_string($overridesRaw) && $overridesRaw !== '') {
            /** @var array<mixed,mixed>|null $decoded */
            $decoded = json_decode($overridesRaw, true);
            if (is_array($decoded)) {
                /** @var array<string,mixed> $decoded */
                $prefs['overrides'] = $this->sanitizeOverrides($decoded);
            }
        }

        /** @var mixed $collapsed */
        $collapsed = $record->getAttribute('sidebar_collapsed');
        $prefs['sidebar']['collapsed'] = $this->toBool($collapsed);

        /** @var mixed $width */
        $width = $record->getAttribute('sidebar_width');
        if (is_int($width) || is_float($width) || (is_string($width) && is_numeric($width))) {
            $prefs['sidebar']['width'] = $this->clampWidth((int) $width);
        }

        /** @var mixed $orderRaw */
        $orderRaw = $record->getAttribute('sidebar_order');
        if (is_string($orderRaw) && $orderRaw !== '') {
            /** @var array<mixed,mixed>|null $decoded */
            $decoded = json_decode($orderRaw, true);
            if (is_array($decoded)) {
                /** @var array<int|string,mixed> $decoded */
                $prefs['sidebar']['order'] = $this->sanitizeOrder(array_values($decoded));
            }
        }

        return $prefs;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return UserPrefs
     */
    public function apply(int $userId, array $input): array
    {
        $current = $this->get($userId);
        $prefs = $this->mergePreferences($current, $input);

        UserUiPreference::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'theme' => $prefs['theme'],
                'mode' => $prefs['mode'],
                'overrides' => json_encode($prefs['overrides'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sidebar_collapsed' => $prefs['sidebar']['collapsed'],
                'sidebar_width' => $prefs['sidebar']['width'],
                'sidebar_order' => json_encode($prefs['sidebar']['order'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        return $prefs;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return UserPrefs
     */
    public function preview(array $input): array
    {
        return $this->mergePreferences($this->defaults(), $input);
    }

    /**
     * @param  UserPrefs  $baseline
     * @param  array<string,mixed>  $input
     * @return UserPrefs
     */
    private function mergePreferences(array $baseline, array $input): array
    {
        /** @var UserPrefs $merged */
        $merged = $baseline;

        if (array_key_exists('theme', $input)) {
            $merged['theme'] = $this->sanitizeTheme($input['theme']);
        }

        if (array_key_exists('mode', $input)) {
            $merged['mode'] = $this->sanitizeMode($input['mode']);
        }

        if (array_key_exists('overrides', $input) && is_array($input['overrides'])) {
            /** @var array<string,mixed> $overridesInput */
            $overridesInput = $input['overrides'];
            $merged['overrides'] = $this->sanitizeOverrides($overridesInput);
        }

        if (array_key_exists('sidebar', $input) && is_array($input['sidebar'])) {
            /** @var array<string,mixed> $sidebarInput */
            $sidebarInput = $input['sidebar'];
            if (array_key_exists('collapsed', $sidebarInput)) {
                $merged['sidebar']['collapsed'] = $this->toBool($sidebarInput['collapsed']);
            }
            if (array_key_exists('width', $sidebarInput)) {
                $merged['sidebar']['width'] = $this->sanitizeWidth($sidebarInput['width']);
            }
            if (array_key_exists('order', $sidebarInput) && is_array($sidebarInput['order'])) {
                /** @var array<int|string,mixed> $orderInput */
                $orderInput = $sidebarInput['order'];
                $merged['sidebar']['order'] = $this->sanitizeOrder(array_values($orderInput));
            }
        }

        return $this->sanitizePrefs($merged);
    }

    /**
     * @param  UserPrefs  $prefs
     */
    public function etagFor(array $prefs): string
    {
        /** @var mixed $normalized */
        $normalized = $this->normalizeForHash($prefs);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode UI preferences for ETag');
        }

        return sprintf('W/"prefs:%s"', hash('sha256', $encoded));
    }

    /**
     * @return UserPrefs
     */
    public function defaults(): array
    {
        return $this->defaultPrefs();
    }

    /** @return UserPrefs */
    private function defaultPrefs(): array
    {
        /** @var array<string,mixed> $defaults */
        $defaults = (array) config('ui.user_defaults', []);

        return $this->sanitizePrefs($defaults);
    }

    /**
     * @param  array<string,mixed>  $prefs
     * @return UserPrefs
     */
    private function sanitizePrefs(array $prefs): array
    {
        /** @var UserPrefs $defaults */
        $defaults = [
            'theme' => null,
            'mode' => null,
            'overrides' => $this->sanitizeOverrides([]),
            'sidebar' => [
                'collapsed' => false,
                'width' => 280,
                'order' => [],
            ],
        ];

        $defaults['theme'] = $this->sanitizeTheme($prefs['theme'] ?? null);
        $defaults['mode'] = $this->sanitizeMode($prefs['mode'] ?? null);

        if (isset($prefs['overrides']) && is_array($prefs['overrides'])) {
            /** @var array<string,mixed> $overridesInput */
            $overridesInput = $prefs['overrides'];
            $defaults['overrides'] = $this->sanitizeOverrides($overridesInput);
        }

        if (isset($prefs['sidebar']) && is_array($prefs['sidebar'])) {
            /** @var array<string,mixed> $sidebar */
            $sidebar = $prefs['sidebar'];
            if (array_key_exists('collapsed', $sidebar)) {
                $defaults['sidebar']['collapsed'] = $this->toBool($sidebar['collapsed']);
            }
            if (array_key_exists('width', $sidebar)) {
                $defaults['sidebar']['width'] = $this->sanitizeWidth($sidebar['width']);
            }
            if (array_key_exists('order', $sidebar) && is_array($sidebar['order'])) {
                /** @var array<int|string,mixed> $orderInput */
                $orderInput = $sidebar['order'];
                $defaults['sidebar']['order'] = $this->sanitizeOrder(array_values($orderInput));
            }
        }

        return $defaults;
    }

    private function sanitizeTheme(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            return null;
        }

        $slug = trim($value);
        if ($slug === '') {
            return null;
        }

        return $slug;
    }

    private function sanitizeMode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            return null;
        }
        $token = strtolower(trim($value));
        if (in_array($token, ['light', 'dark'], true)) {
            return $token;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return ThemeOverrides
     */
    private function sanitizeOverrides(array $input): array
    {
        /** @var array<int,string> $allowed */
        $allowed = (array) config('ui.overrides.allowed_keys', []);
        /** @var ThemeOverrides $result */
        $result = [];

        foreach ($allowed as $token) {
            $result[$token] = $this->userDefaultOverride($token);
        }

        $shadowPresets = $this->presetOptions((array) config('ui.overrides.shadow_presets', []), 'default');
        $spacingPresets = $this->presetOptions((array) config('ui.overrides.spacing_presets', []), 'default');
        $typeScalePresets = $this->presetOptions((array) config('ui.overrides.type_scale_presets', []), 'medium');
        $motionPresets = $this->presetOptions((array) config('ui.overrides.motion_presets', []), 'full');

        /** @var mixed $value */
        foreach ($input as $token => $value) {
            if (! in_array($token, $allowed, true)) {
                continue;
            }

            if (str_starts_with($token, 'color.')) {
                if (is_string($value) && trim($value) !== '') {
                    $result[$token] = $value;
                }

                continue;
            }

            $presets = match ($token) {
                'shadow' => $shadowPresets,
                'spacing' => $spacingPresets,
                'typeScale' => $typeScalePresets,
                'motion' => $motionPresets,
                default => [],
            };

            if ($presets !== [] && is_string($value)) {
                $candidate = trim($value);
                if ($candidate !== '' && in_array($candidate, $presets, true)) {
                    $result[$token] = $candidate;
                }
            }
        }

        return $result;
    }

    private function userDefaultOverride(string $token): ?string
    {
        /** @var array<string,mixed> $defaults */
        $defaults = (array) config('ui.user_defaults.overrides', []);
        /** @var mixed $value */
        $value = $defaults[$token] ?? null;

        return is_string($value) ? $value : null;
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
     * @param  array<int,mixed>  $order
     * @return array<int,string>
     */
    private function sanitizeOrder(array $order): array
    {
        $result = [];
        foreach ($order as $item) {
            if (! is_string($item)) {
                continue;
            }
            $token = trim($item);
            if ($token !== '') {
                $result[] = $token;
            }
        }

        return $result;
    }

    private function sanitizeWidth(mixed $value): int
    {
        if (is_int($value)) {
            return $this->clampWidth($value);
        }
        if (is_float($value)) {
            return $this->clampWidth((int) round($value));
        }
        if (is_string($value) && is_numeric($value)) {
            return $this->clampWidth((int) round((float) $value));
        }

        return $this->clampWidth(280);
    }

    private function clampWidth(int $width): int
    {
        return max(self::MIN_SIDEBAR_WIDTH, min(self::MAX_SIDEBAR_WIDTH, $width));
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
}
