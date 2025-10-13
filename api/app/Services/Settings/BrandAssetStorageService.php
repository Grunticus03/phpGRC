<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\BrandAsset;
use App\Models\BrandProfile;
use App\Models\UiSetting;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class BrandAssetStorageService
{
    private const STORAGE_KEY_PATH = 'ui.brand.assets.filesystem_path';

    public function __construct(private readonly Filesystem $filesystem) {}

    /**
     * @return array{filesystem_path:string}
     */
    public function storageConfig(): array
    {
        $path = $this->defaultPath();

        /** @var UiSetting|null $row */
        $row = UiSetting::query()
            ->where('ui_settings.key', '=', self::STORAGE_KEY_PATH)
            ->first(['value', 'type']);

        if ($row !== null) {
            /** @var mixed $valueRaw */
            $valueRaw = $row->getAttribute('value');
            /** @var mixed $typeRaw */
            $typeRaw = $row->getAttribute('type');
            if (is_string($valueRaw) && is_string($typeRaw)) {
                /** @var mixed $decoded */
                $decoded = $this->decodeValue($valueRaw, $typeRaw);
                if (is_string($decoded)) {
                    $path = $this->sanitizePath($decoded, $path);
                }
            }
        }

        return [
            'filesystem_path' => $path,
        ];
    }

    public function filesystemPath(): string
    {
        return $this->storageConfig()['filesystem_path'];
    }

    public function ensureStorageDirectory(): string
    {
        $path = $this->filesystemPath();
        if (! $this->filesystem->isDirectory($path)) {
            if (! $this->filesystem->makeDirectory($path, 0750, true)) {
                throw new \RuntimeException(sprintf('Unable to create brand asset directory: %s', $path));
            }
        }

        return $path;
    }

    public function ensureProfileDirectory(string $profileId): string
    {
        $root = $this->ensureStorageDirectory();
        $segment = $this->sanitizeSegment($profileId);
        $directory = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$segment;
        if (! $this->filesystem->isDirectory($directory)) {
            if (! $this->filesystem->makeDirectory($directory, 0750, true)) {
                throw new \RuntimeException(sprintf('Unable to create brand profile directory: %s', $directory));
            }
        }

        return $directory;
    }

    public function profileDirectory(BrandProfile $profile): string
    {
        /** @var mixed $id */
        $id = $profile->getAttribute('id');
        if (! is_string($id) || trim($id) === '') {
            throw new \RuntimeException('Brand profile id must be a non-empty string.');
        }

        return $this->ensureProfileDirectory($id);
    }

    public function writeAsset(BrandAsset $asset, string $bytes): string
    {
        $filePath = $this->pathForAsset($asset, true);
        if ($filePath === null) {
            throw new \RuntimeException('Unable to resolve filesystem path for brand asset.');
        }

        if ($this->filesystem->put($filePath, $bytes) === false) {
            throw new \RuntimeException(sprintf('Unable to write brand asset file: %s', $filePath));
        }

        return $filePath;
    }

    public function readAsset(BrandAsset $asset): ?string
    {
        $filePath = $this->pathForAsset($asset, false);
        if ($filePath === null) {
            return null;
        }

        if (! $this->filesystem->exists($filePath)) {
            return null;
        }

        return $this->filesystem->get($filePath);
    }

    public function deleteAsset(BrandAsset $asset): void
    {
        $filePath = $this->pathForAsset($asset, false);
        if ($filePath === null) {
            return;
        }

        if (! $this->filesystem->exists($filePath)) {
            return;
        }

        if (! $this->filesystem->delete($filePath)) {
            throw new \RuntimeException(sprintf('Unable to delete brand asset file: %s', $filePath));
        }
    }

    public function assetPath(BrandAsset $asset): ?string
    {
        return $this->pathForAsset($asset, false);
    }

    public function sanitizePath(mixed $value, ?string $fallback = null): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback !== null ? $this->normalizePath($fallback) : $this->defaultPath();
        }

        return $this->normalizePath($value);
    }

    public function defaultPath(): string
    {
        /** @var mixed $defaultRaw */
        $defaultRaw = config('ui.defaults.brand.assets.filesystem_path', '/opt/phpgrc/shared/brands');
        $default = is_string($defaultRaw) ? $defaultRaw : '/opt/phpgrc/shared/brands';

        return $this->normalizePath($default);
    }

    private function pathForAsset(BrandAsset $asset, bool $ensureDirectory): ?string
    {
        /** @var mixed $profileIdRaw */
        $profileIdRaw = $asset->getAttribute('profile_id');
        if (! is_string($profileIdRaw) || trim($profileIdRaw) === '') {
            return null;
        }

        $directory = $ensureDirectory
            ? $this->ensureProfileDirectory($profileIdRaw)
            : $this->profileDirectoryFromId($profileIdRaw);

        if ($directory === null) {
            return null;
        }

        $filename = $this->fileNameForAsset($asset);

        return rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
    }

    private function profileDirectoryFromId(string $profileId): ?string
    {
        $root = $this->filesystemPath();
        $segment = $this->sanitizeSegment($profileId);
        $directory = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$segment;

        return $this->filesystem->isDirectory($directory) ? $directory : null;
    }

    private function fileNameForAsset(BrandAsset $asset): string
    {
        /** @var mixed $idRaw */
        $idRaw = $asset->getAttribute('id');
        $id = is_string($idRaw) && $idRaw !== '' ? $idRaw : Str::ulid()->toBase32();

        /** @var mixed $nameRaw */
        $nameRaw = $asset->getAttribute('name');
        $name = is_string($nameRaw) ? $nameRaw : '';
        /** @var string $extensionRaw */
        $extensionRaw = pathinfo($name, PATHINFO_EXTENSION);
        $extension = strtolower($extensionRaw);

        if ($extension === '') {
            /** @var mixed $mimeRaw */
            $mimeRaw = $asset->getAttribute('mime');
            $mime = is_string($mimeRaw) ? strtolower(trim($mimeRaw)) : '';
            $extension = $this->extensionForMime($mime);
        }

        $safeId = preg_replace('/[^a-z0-9_-]+/i', '-', $id) ?? $id;
        $safeId = trim($safeId, '-');
        if ($safeId === '') {
            $safeId = Str::ulid()->toBase32();
        }

        if ($extension !== '') {
            $safeExtension = preg_replace('/[^a-z0-9]+/i', '', $extension) ?? '';
            $safeExtension = strtolower($safeExtension);
            if ($safeExtension !== '') {
                return sprintf('%s.%s', $safeId, $safeExtension);
            }
        }

        return $safeId;
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => '',
        };
    }

    private function sanitizeSegment(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        if ($normalized === '') {
            $normalized = 'brand-profile';
        }

        return $normalized;
    }

    private function normalizePath(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $this->defaultPath();
        }

        if (! Str::startsWith($trimmed, DIRECTORY_SEPARATOR)) {
            return DIRECTORY_SEPARATOR.ltrim($trimmed, DIRECTORY_SEPARATOR);
        }

        return $trimmed;
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
