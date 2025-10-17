<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Exceptions\DesignerThemeException;
use App\Models\UiSetting;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

final class DesignerThemeStorageService
{
    private const STORAGE_KEY_STORAGE = 'ui.theme.designer.storage';

    private const STORAGE_KEY_PATH = 'ui.theme.designer.filesystem_path';

    private const EXPORT_VERSION = 1;

    private const FILE_EXTENSION = 'json';

    public function __construct(private readonly Filesystem $filesystem) {}

    /**
     * @return array{
     *     storage:string,
     *     filesystem_path:string
     * }
     */
    public function storageConfig(): array
    {
        /** @var mixed $storageDefaultRaw */
        $storageDefaultRaw = config('ui.defaults.theme.designer.storage');
        /** @var mixed $pathDefaultRaw */
        $pathDefaultRaw = config('ui.defaults.theme.designer.filesystem_path');

        $defaults = [
            'storage' => $this->normalizeStorageToken(
                is_string($storageDefaultRaw) ? $storageDefaultRaw : 'filesystem'
            ),
            'filesystem_path' => $this->normalizePath(
                is_string($pathDefaultRaw) ? $pathDefaultRaw : '/opt/phpgrc/shared/themes'
            ),
        ];

        /** @var Collection<int,UiSetting> $rows */
        $rows = UiSetting::query()
            ->whereIn('key', [self::STORAGE_KEY_STORAGE, self::STORAGE_KEY_PATH])
            ->get(['key', 'value', 'type']);

        $config = $defaults;

        foreach ($rows as $row) {
            /** @var string $key */
            $key = $row->getAttribute('key');
            /** @var mixed $valueRaw */
            $valueRaw = $row->getAttribute('value');
            /** @var mixed $typeRaw */
            $typeRaw = $row->getAttribute('type');
            if (! is_string($valueRaw) || ! is_string($typeRaw)) {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = $this->decodeValue($valueRaw, $typeRaw);

            if ($key === self::STORAGE_KEY_STORAGE && is_string($decoded)) {
                $config['storage'] = $this->normalizeStorageToken($decoded);
            }

            if ($key === self::STORAGE_KEY_PATH && is_string($decoded)) {
                $config['filesystem_path'] = $this->normalizePath($decoded);
            }
        }

        return $config;
    }

    /**
     * @return list<array{
     *     slug:string,
     *     name:string,
     *     source:string,
     *     supports:array{mode:list<string>},
     *     variables:array<string,string>
     * }>
     */
    public function manifestEntries(): array
    {
        $config = $this->storageConfig();
        if ($config['storage'] !== 'filesystem') {
            return [];
        }

        $directory = $config['filesystem_path'];
        if (! $this->filesystem->isDirectory($directory)) {
            return [];
        }

        $entries = [];

        /** @var SplFileInfo[] $files */
        $files = $this->filesystem->files($directory);
        foreach ($files as $file) {
            $path = $file->getPathname();
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($extension !== self::FILE_EXTENSION) {
                continue;
            }

            $contents = $this->filesystem->get($path);
            if (trim($contents) === '') {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($contents, true);
            if (! is_array($decoded)) {
                continue;
            }

            /** @var array<string,mixed> $decodedArray */
            $decodedArray = $decoded;
            $pack = $this->transformDecoded($decodedArray);
            if ($pack !== null) {
                $entries[$pack['slug']] = $pack;
            }
        }

        ksort($entries);

        return array_values($entries);
    }

    /**
     * @param  array<string,string>  $variables
     * @param  list<string>|null  $supportsModes
     * @return array{
     *     slug:string,
     *     name:string,
     *     source:string,
     *     supports:array{mode:list<string>},
     *     variables:array<string,string>
     * }
     */
    public function save(string $name, string $slug, array $variables, ?int $actorId = null, ?array $supportsModes = null): array
    {
        $config = $this->storageConfig();
        if ($config['storage'] !== 'filesystem') {
            throw new DesignerThemeException(
                'DESIGNER_STORAGE_DISABLED',
                'Filesystem storage is not enabled for custom themes.',
                409
            );
        }

        $normalizedName = $this->sanitizeName($name);
        if ($normalizedName === '') {
            throw new DesignerThemeException('DESIGNER_THEME_INVALID', 'Theme name is required.');
        }

        $normalizedSlug = $this->sanitizeSlug($slug);
        if ($normalizedSlug === '') {
            throw new DesignerThemeException('DESIGNER_THEME_INVALID', 'Theme slug is invalid.');
        }

        $sanitizedVariables = $this->sanitizeVariables($variables);
        if ($sanitizedVariables === []) {
            throw new DesignerThemeException('DESIGNER_THEME_INVALID', 'At least one variable must be provided.');
        }

        $modes = $this->sanitizeSupports($supportsModes);

        $directory = $config['filesystem_path'];
        if (! $this->filesystem->isDirectory($directory)) {
            if (! $this->filesystem->makeDirectory($directory, 0750, true)) {
                throw new DesignerThemeException(
                    'DESIGNER_THEME_IO_ERROR',
                    sprintf('Unable to create directory: %s', $directory),
                    500
                );
            }
        }

        $path = $this->pathForSlug($normalizedSlug, $directory);
        /** @var array<string,mixed> $payload */
        $payload = [
            'version' => self::EXPORT_VERSION,
            'slug' => $normalizedSlug,
            'name' => $normalizedName,
            'variables' => $sanitizedVariables,
            'supports' => ['mode' => $modes],
            'source' => 'custom',
            'updated_at' => Carbon::now('UTC')->toIso8601String(),
            'updated_by' => $actorId,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new DesignerThemeException('DESIGNER_THEME_IO_ERROR', 'Failed to encode theme payload.', 500);
        }

        if ($this->filesystem->put($path, $encoded) === false) {
            throw new DesignerThemeException(
                'DESIGNER_THEME_IO_ERROR',
                sprintf('Unable to write theme file: %s', $path),
                500
            );
        }

        /** @var array<string,mixed> $payloadForTransform */
        $payloadForTransform = $payload;

        return $this->transformDecoded($payloadForTransform) ?? [
            'slug' => $normalizedSlug,
            'name' => $normalizedName,
            'source' => 'custom',
            'supports' => ['mode' => $modes],
            'variables' => $sanitizedVariables,
        ];
    }

    /**
     * @return array{filename:string,contents:string,pack:array{
     *     slug:string,
     *     name:string,
     *     source:string,
     *     supports:array{mode:list<string>},
     *     variables:array<string,string>
     * }}
     */
    public function export(string $slug): array
    {
        $config = $this->storageConfig();
        if ($config['storage'] !== 'filesystem') {
            throw new DesignerThemeException(
                'DESIGNER_STORAGE_DISABLED',
                'Filesystem storage is not enabled for custom themes.',
                409
            );
        }

        $normalizedSlug = $this->sanitizeSlug($slug);
        if ($normalizedSlug === '') {
            throw new DesignerThemeException('DESIGNER_THEME_INVALID', 'Theme slug is invalid.', 422);
        }

        $path = $this->pathForSlug($normalizedSlug, $config['filesystem_path']);
        if (! $this->filesystem->exists($path)) {
            throw new DesignerThemeException('DESIGNER_THEME_NOT_FOUND', 'Theme not found.', 404);
        }

        $contents = $this->filesystem->get($path);
        if (trim($contents) === '') {
            throw new DesignerThemeException(
                'DESIGNER_THEME_IO_ERROR',
                sprintf('Theme file is empty: %s', $path),
                500
            );
        }

        /** @var mixed $decodedRaw */
        $decodedRaw = json_decode($contents, true);
        if (! is_array($decodedRaw)) {
            throw new DesignerThemeException(
                'DESIGNER_THEME_IO_ERROR',
                sprintf('Unable to decode theme file: %s', $path),
                500
            );
        }

        /** @var array<string,mixed> $decoded */
        $decoded = $decodedRaw;
        $pack = $this->transformDecoded($decoded);
        if ($pack === null) {
            throw new DesignerThemeException(
                'DESIGNER_THEME_IO_ERROR',
                sprintf('Theme file is invalid: %s', $path),
                500
            );
        }

        $exportPayload = $this->buildExportPayload($decoded, $pack);

        $encoded = json_encode($exportPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new DesignerThemeException('DESIGNER_THEME_IO_ERROR', 'Unable to encode theme export payload.', 500);
        }

        return [
            'filename' => sprintf('%s.theme.json', $pack['slug']),
            'contents' => $encoded,
            'pack' => $pack,
        ];
    }

    /**
     * @return array{
     *     slug:string,
     *     name:string,
     *     variables:array<string,string>,
     *     supports:list<string>
     * }
     */
    public function parseImportPayload(string $contents, ?string $slugOverride = null): array
    {
        $trimmed = trim($contents);
        if ($trimmed === '') {
            throw new DesignerThemeException('DESIGNER_THEME_IMPORT_INVALID', 'Theme file is empty.');
        }

        /** @var mixed $decodedRaw */
        $decodedRaw = json_decode($trimmed, true);
        if (! is_array($decodedRaw)) {
            throw new DesignerThemeException('DESIGNER_THEME_IMPORT_INVALID', 'Theme file must be valid JSON.');
        }

        /** @var array<string,mixed> $decoded */
        $decoded = $decodedRaw;

        if (isset($decoded['theme']) && is_array($decoded['theme'])) {
            /** @var array<string,mixed> $theme */
            $theme = $decoded['theme'];
            $decoded = $theme;
        }

        $slugCandidate = $slugOverride ?? (is_string($decoded['slug'] ?? null) ? (string) $decoded['slug'] : '');
        $nameCandidate = is_string($decoded['name'] ?? null) ? (string) $decoded['name'] : '';
        /** @var mixed $variablesRaw */
        $variablesRaw = $decoded['variables'] ?? null;

        if (! is_array($variablesRaw)) {
            throw new DesignerThemeException('DESIGNER_THEME_IMPORT_INVALID', 'Theme variables must be an object.');
        }

        $variables = $this->normalizeImportVariables($variablesRaw);
        if ($variables === []) {
            throw new DesignerThemeException('DESIGNER_THEME_IMPORT_INVALID', 'Theme variables are required.');
        }

        $normalizedSlug = $this->sanitizeSlug($slugCandidate);
        if ($normalizedSlug === '') {
            throw new DesignerThemeException('DESIGNER_THEME_IMPORT_INVALID', 'Theme slug is missing or invalid.');
        }

        $normalizedName = $this->sanitizeName($nameCandidate);
        if ($normalizedName === '') {
            throw new DesignerThemeException('DESIGNER_THEME_IMPORT_INVALID', 'Theme name is required.');
        }

        $supportsCandidate = null;
        if (isset($decoded['supports']) && is_array($decoded['supports'])) {
            /** @var mixed $modeRaw */
            $modeRaw = $decoded['supports']['mode'] ?? null;
            if (is_array($modeRaw)) {
                $supportsCandidate = array_values($modeRaw);
            }
        }

        $modes = $this->sanitizeSupports($supportsCandidate);

        return [
            'slug' => $normalizedSlug,
            'name' => $normalizedName,
            'variables' => $variables,
            'supports' => $modes,
        ];
    }

    public function delete(string $slug): void
    {
        $config = $this->storageConfig();
        if ($config['storage'] !== 'filesystem') {
            throw new DesignerThemeException(
                'DESIGNER_STORAGE_DISABLED',
                'Filesystem storage is not enabled for custom themes.',
                409
            );
        }

        $normalizedSlug = $this->sanitizeSlug($slug);
        if ($normalizedSlug === '') {
            throw new DesignerThemeException('DESIGNER_THEME_INVALID', 'Theme slug is invalid.');
        }

        $path = $this->pathForSlug($normalizedSlug, $config['filesystem_path']);
        if (! $this->filesystem->exists($path)) {
            return;
        }

        if (! $this->filesystem->delete($path)) {
            throw new DesignerThemeException(
                'DESIGNER_THEME_IO_ERROR',
                sprintf('Unable to delete theme file: %s', $path),
                500
            );
        }
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array{
     *     slug:string,
     *     name:string,
     *     source:string,
     *     supports:array{mode:list<string>},
     *     variables:array<string,string>
     * }|null
     */
    private function transformDecoded(array $decoded): ?array
    {
        $slug = isset($decoded['slug']) && is_string($decoded['slug'])
            ? $this->sanitizeSlug($decoded['slug'])
            : '';
        if ($slug === '') {
            return null;
        }

        $name = isset($decoded['name']) && is_string($decoded['name'])
            ? $this->sanitizeName($decoded['name'])
            : '';
        if ($name === '') {
            return null;
        }

        /** @var mixed $variablesRaw */
        $variablesRaw = $decoded['variables'] ?? null;
        $variables = [];
        if (is_array($variablesRaw)) {
            /** @var array<string,mixed> $variablesArray */
            $variablesArray = $variablesRaw;
            $variables = $this->sanitizeVariables($variablesArray);
        }
        if ($variables === []) {
            return null;
        }

        $supportsCandidate = null;
        if (isset($decoded['supports']) && is_array($decoded['supports'])) {
            /** @var mixed $supportsRaw */
            $supportsRaw = $decoded['supports']['mode'] ?? null;
            if (is_array($supportsRaw)) {
                $supportsCandidate = array_values($supportsRaw);
            }
        }

        $supports = ['mode' => $this->sanitizeSupports($supportsCandidate)];

        return [
            'slug' => $slug,
            'name' => $name,
            'source' => 'custom',
            'supports' => $supports,
            'variables' => $variables,
        ];
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,string>
     */
    private function sanitizeVariables(array $variables): array
    {
        $result = [];
        foreach ($variables as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $variable = trim($key);
            $token = trim((string) $value);
            if ($token === '') {
                continue;
            }

            $result[$variable] = $token;
        }

        return $result;
    }

    private function sanitizeName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) > 160) {
            return mb_substr($trimmed, 0, 160);
        }

        return $trimmed;
    }

    private function sanitizeSlug(string $slug): string
    {
        $normalized = strtolower(trim($slug));
        $normalized = preg_replace('/[^a-z0-9-]+/', '-', $normalized) ?? '';
        $normalized = trim(preg_replace('/-+/', '-', $normalized) ?? '', '-');

        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) > 64) {
            $normalized = mb_substr($normalized, 0, 64);
        }

        return $normalized;
    }

    private function pathForSlug(string $slug, string $directory): string
    {
        $filename = sprintf('%s.%s', $slug, self::FILE_EXTENSION);

        return rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
    }

    private function normalizeStorageToken(string $value): string
    {
        $token = strtolower(trim($value));

        return in_array($token, ['browser', 'filesystem'], true) ? $token : 'filesystem';
    }

    private function normalizePath(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            /** @var mixed $fallbackRaw */
            $fallbackRaw = config('ui.defaults.theme.designer.filesystem_path', '/opt/phpgrc/shared/themes');
            $fallback = is_string($fallbackRaw) ? trim($fallbackRaw) : '';

            return $fallback !== '' ? $fallback : '/opt/phpgrc/shared/themes';
        }

        if (! Str::startsWith($trimmed, '/')) {
            return '/'.ltrim($trimmed, '/');
        }

        return $trimmed;
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @param  array{
     *     slug:string,
     *     name:string,
     *     source:string,
     *     supports:array{mode:list<string>},
     *     variables:array<string,string>
     * } $pack
     * @return array<string,mixed>
     */
    private function buildExportPayload(array $decoded, array $pack): array
    {
        /** @var mixed $versionRaw */
        $versionRaw = $decoded['version'] ?? null;
        $version = is_int($versionRaw) && $versionRaw >= 1 ? $versionRaw : self::EXPORT_VERSION;

        /** @var array<string,string> $variables */
        $variables = $pack['variables'];
        ksort($variables);

        $payload = [
            'version' => $version,
            'slug' => $pack['slug'],
            'name' => $pack['name'],
            'supports' => $pack['supports'],
            'variables' => $variables,
            'source' => 'custom',
            'exported_at' => Carbon::now('UTC')->toIso8601String(),
        ];

        if (isset($decoded['updated_at']) && is_string($decoded['updated_at']) && trim($decoded['updated_at']) !== '') {
            $payload['updated_at'] = $decoded['updated_at'];
        }

        if (array_key_exists('updated_by', $decoded)) {
            /** @var mixed $updatedBy */
            $updatedBy = $decoded['updated_by'] ?? null;
            if ($updatedBy === null || is_int($updatedBy) || (is_string($updatedBy) && trim($updatedBy) !== '')) {
                $payload['updated_by'] = $updatedBy;
            }
        }

        return $payload;
    }

    /**
     * @param  array<array-key,mixed>  $variablesRaw
     * @return array<string,string>
     */
    private function normalizeImportVariables(array $variablesRaw): array
    {
        if ($variablesRaw !== [] && array_is_list($variablesRaw)) {
            /** @var array<string,mixed> $map */
            $map = [];
            foreach ($variablesRaw as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                /** @var array{key?: mixed, value?: mixed} $entry */
                $key = $entry['key'] ?? null;
                $value = $entry['value'] ?? null;
                if (! is_scalar($value) && $value !== null) {
                    continue;
                }
                if (! is_string($key)) {
                    continue;
                }

                $map[$key] = $value;
            }

            return $this->sanitizeVariables($map);
        }

        /** @var array<string,mixed> $assoc */
        $assoc = $variablesRaw;

        return $this->sanitizeVariables($assoc);
    }

    /**
     * @param  list<mixed>|null  $modes
     * @return list<string>
     */
    private function sanitizeSupports(?array $modes): array
    {
        if (! is_array($modes)) {
            return ['light', 'dark'];
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static function (mixed $value): ?string {
                if (! is_string($value)) {
                    return null;
                }

                $mode = strtolower(trim($value));

                return in_array($mode, ['light', 'dark'], true) ? $mode : null;
            },
            $modes
        ))));

        return $normalized !== [] ? $normalized : ['light', 'dark'];
    }

    private function decodeValue(string $value, string $type): mixed
    {
        return match ($type) {
            'json' => $this->decodeJson($value),
            'bool' => $value === '1',
            'int' => (int) $value,
            default => $value,
        };
    }

    private function decodeJson(string $value): mixed
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
