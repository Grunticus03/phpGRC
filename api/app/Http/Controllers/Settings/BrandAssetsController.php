<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\BrandAssetUploadRequest;
use App\Models\BrandAsset;
use App\Models\BrandProfile;
use App\Services\Settings\BrandAssetStorageService;
use App\Services\Settings\UiSettingsService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BrandAssetsController extends Controller
{
    /**
     * @var array<string,array{width:int,height:int,mime:string,extension:string,quality?:int}>
     */
    private const VARIANT_SPECS = [
        'primary_logo' => ['width' => 480, 'height' => 180, 'quality' => 90, 'mime' => 'image/webp', 'extension' => 'webp'],
        'secondary_logo' => ['width' => 360, 'height' => 140, 'quality' => 90, 'mime' => 'image/webp', 'extension' => 'webp'],
        'header_logo' => ['width' => 280, 'height' => 100, 'quality' => 85, 'mime' => 'image/webp', 'extension' => 'webp'],
        'footer_logo' => ['width' => 280, 'height' => 100, 'quality' => 85, 'mime' => 'image/webp', 'extension' => 'webp'],
        'favicon' => ['width' => 64, 'height' => 64, 'quality' => 95, 'mime' => 'image/x-icon', 'extension' => 'ico'],
    ];

    public function __construct(
        private readonly UiSettingsService $settings,
        private readonly BrandAssetStorageService $storage
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var mixed $profileInput */
        $profileInput = $request->query('profile_id');
        $profileId = is_string($profileInput) ? trim($profileInput) : null;

        $profile = $profileId !== null && $profileId !== ''
            ? $this->settings->brandProfileById($profileId)
            : $this->settings->activeBrandProfile();

        if (! $profile instanceof BrandProfile) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => 'Branding profile not found.',
            ], 404);
        }

        /** @var array<int, BrandAsset> $assets */
        $assets = BrandAsset::query()
            ->orderByDesc('created_at')
            ->get()
            ->all();

        $data = array_map(
            fn (BrandAsset $asset): array => $this->transform($asset),
            $assets
        );

        return response()->json([
            'ok' => true,
            'assets' => $data,
        ], 200);
    }

    public function store(BrandAssetUploadRequest $request): JsonResponse
    {
        /** @var mixed $rawFile */
        $rawFile = $request->file('file');
        $uploaded = $rawFile instanceof UploadedFile
            ? $rawFile
            : (is_array($rawFile) ? ($rawFile[0] ?? null) : null);

        if (! $uploaded instanceof UploadedFile) {
            return response()->json([
                'ok' => false,
                'code' => 'UPLOAD_FAILED',
                'message' => 'No file provided.',
            ], 400);
        }

        $bytes = $uploaded->get();
        if (! is_string($bytes)) {
            return response()->json([
                'ok' => false,
                'code' => 'UPLOAD_FAILED',
                'message' => 'Upload failed: unable to read file bytes.',
            ], 500);
        }

        $size = strlen($bytes);

        if ($size > 5 * 1024 * 1024) {
            return response()->json([
                'ok' => false,
                'code' => 'PAYLOAD_TOO_LARGE',
                'message' => 'File exceeds 5 MB limit.',
            ], 413);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($bytes);
        if (is_string($detectedMime) && $detectedMime !== '') {
            $mime = $detectedMime;
        } else {
            $fallbackMime = $uploaded->getMimeType();
            $mime = is_string($fallbackMime) && $fallbackMime !== '' ? $fallbackMime : 'application/octet-stream';
        }

        $allowed = [
            'image/png',
            'image/jpeg',
            'image/webp',
        ];

        if (! in_array($mime, $allowed, true)) {
            return response()->json([
                'ok' => false,
                'code' => 'UNSUPPORTED_MEDIA_TYPE',
                'message' => sprintf('Unsupported MIME type: %s', $mime),
            ], 415);
        }

        $originalName = $uploaded->getClientOriginalName();
        $name = trim($originalName);
        if ($name === '') {
            $name = 'upload';
        }
        if (mb_strlen($name) > 160) {
            $name = mb_substr($name, 0, 160);
        }

        $user = $request->user();
        $actorId = null;
        $actorName = null;
        if ($user !== null) {
            /** @var mixed $id */
            $id = $user->getAuthIdentifier();
            if (is_int($id)) {
                $actorId = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $actorId = (int) $id;
            }

            /** @var mixed $nameAttr */
            $nameAttr = $user->getAttribute('name');
            if (is_string($nameAttr) && trim($nameAttr) !== '') {
                $actorName = trim($nameAttr);
            }
        }

        /** @var mixed $profileInput */
        $profileInput = $request->input('profile_id');
        $profileId = is_string($profileInput) ? trim($profileInput) : null;
        if ($profileId === null || $profileId === '') {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_REQUIRED',
                'message' => 'Branding profile is required.',
            ], 422);
        }

        $profile = $this->settings->brandProfileById($profileId);
        if (! $profile instanceof BrandProfile) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => 'Branding profile not found.',
            ], 404);
        }

        if ($profile->getAttribute('is_default')) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_LOCKED',
                'message' => 'Default branding profile cannot be modified.',
            ], 409);
        }

        /** @var mixed $kindInput */
        $kindInput = $request->input('kind', 'primary_logo');
        $kind = is_string($kindInput) ? $kindInput : 'primary_logo';
        if ($kind !== 'primary_logo') {
            return response()->json([
                'ok' => false,
                'code' => 'UNSUPPORTED_KIND',
                'message' => 'Only primary logo uploads are supported. Variants are generated automatically.',
            ], 422);
        }

        $baseSlug = $this->baseFileSlug($name);
        $groupKey = Str::lower(Str::ulid()->toBase32());

        try {
            $variants = $this->createVariantImages($bytes, $baseSlug, $groupKey);
        } catch (\RuntimeException $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'code' => 'IMAGE_PROCESSING_FAILED',
                'message' => $exception->getMessage(),
            ], 415);
        }

        /** @var array<string, BrandAsset> $created */
        $created = [];

        DB::transaction(function () use (&$created, $variants, $profile, $actorId, $actorName): void {
            foreach ($variants as $variantKind => $variant) {
                $record = new BrandAsset([
                    'profile_id' => $profile->getAttribute('id'),
                    'kind' => $variantKind,
                    'name' => $variant['name'],
                    'mime' => $variant['mime'],
                    'size_bytes' => $variant['size_bytes'],
                    'sha256' => $variant['sha256'],
                    'bytes' => $variant['bytes'],
                    'uploaded_by' => $actorId,
                    'uploaded_by_name' => $actorName,
                ]);

                $record->save();
                $created[$variantKind] = $record;
            }
        });

        try {
            foreach ($created as $asset) {
                /** @var mixed $rawBytes */
                $rawBytes = $asset->getAttribute('bytes');
                if (! is_string($rawBytes)) {
                    throw new \RuntimeException('Missing asset bytes.');
                }
                $this->storage->writeAsset($asset, $rawBytes);
            }
        } catch (\Throwable $exception) {
            report($exception);
            DB::transaction(function () use ($created): void {
                foreach ($created as $asset) {
                    /** @var mixed $storedId */
                    $storedId = $asset->getAttribute('id');
                    $cleanupId = is_string($storedId) ? $storedId : null;
                    $asset->delete();
                    if ($cleanupId !== null) {
                        $this->settings->clearBrandAssetReference($cleanupId);
                    }
                }
            });

            return response()->json([
                'ok' => false,
                'code' => 'STORAGE_ERROR',
                'message' => 'Unable to persist brand asset variants to filesystem.',
            ], 500);
        }

        $assetsPayload = array_map(
            fn (BrandAsset $asset): array => $this->transform($asset),
            $created
        );

        return response()->json([
            'ok' => true,
            'asset' => $this->transform($created['primary_logo']),
            'variants' => $assetsPayload,
        ], 201);
    }

    public function download(string $assetId): Response
    {
        /** @var BrandAsset|null $asset */
        $asset = BrandAsset::query()->find($assetId);
        if ($asset === null) {
            return response()->noContent(404);
        }

        $rawBytes = $this->storage->readAsset($asset);
        if (! is_string($rawBytes) || $rawBytes === '') {
            /** @var mixed $fallbackBytes */
            $fallbackBytes = $asset->getAttribute('bytes');
            $rawBytes = is_string($fallbackBytes) ? $fallbackBytes : null;
        }

        if (! is_string($rawBytes) || $rawBytes === '') {
            return response()->noContent(404);
        }

        /** @var mixed $mimeAttr */
        $mimeAttr = $asset->getAttribute('mime');
        $mime = is_string($mimeAttr) && $mimeAttr !== '' ? $mimeAttr : 'application/octet-stream';

        /** @var mixed $sizeAttr */
        $sizeAttr = $asset->getAttribute('size_bytes');
        $size = is_numeric($sizeAttr) ? (int) $sizeAttr : strlen($rawBytes);

        /** @var mixed $nameAttr */
        $nameAttr = $asset->getAttribute('name');
        $name = is_string($nameAttr) && $nameAttr !== '' ? $nameAttr : 'asset';

        /** @var mixed $shaAttr */
        $shaAttr = $asset->getAttribute('sha256');
        $etag = is_string($shaAttr) && $shaAttr !== '' ? sprintf('W/"brand:%s"', $shaAttr) : null;

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Cache-Control' => 'public, max-age=3600, immutable',
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
        ];

        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        return response($rawBytes, 200, $headers);
    }

    public function destroy(Request $request, string $assetId): JsonResponse
    {
        /** @var BrandAsset|null $asset */
        $asset = BrandAsset::query()->find($assetId);
        if ($asset === null) {
            return response()->json([
                'ok' => false,
                'code' => 'NOT_FOUND',
                'message' => 'Asset not found.',
            ], 404);
        }

        /** @var mixed $profileIdRaw */
        $profileIdRaw = $asset->getAttribute('profile_id');
        $profileId = is_string($profileIdRaw) && $profileIdRaw !== '' ? $profileIdRaw : null;

        $assetsToDelete = [$asset];

        /** @var mixed $kindRaw */
        $kindRaw = $asset->getAttribute('kind');
        $kind = is_string($kindRaw) ? $kindRaw : '';

        if ($kind === 'primary_logo' && $profileId !== null) {
            /** @var mixed $nameRaw */
            $nameRaw = $asset->getAttribute('name');
            $name = is_string($nameRaw) ? $nameRaw : null;
            $groupPrefix = $name !== null ? $this->variantGroupPrefix($name) : null;
            if ($groupPrefix !== null) {
                $assetsToDelete = BrandAsset::query()
                    ->where('profile_id', $profileId)
                    ->where('name', 'like', $groupPrefix.'%')
                    ->get()
                    ->all();
            }
        }

        DB::transaction(function () use ($assetsToDelete): void {
            foreach ($assetsToDelete as $entry) {
                /** @var mixed $storedId */
                $storedId = $entry->getAttribute('id');
                $entryId = is_string($storedId) ? $storedId : (is_scalar($storedId) ? (string) $storedId : null);
                $entry->delete();
                if ($entryId !== null) {
                    $this->settings->clearBrandAssetReference($entryId);
                }
            }
        });

        foreach ($assetsToDelete as $entry) {
            try {
                $this->storage->deleteAsset($entry);
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json([
                    'ok' => false,
                    'code' => 'STORAGE_ERROR',
                    'message' => 'Unable to delete brand asset file from filesystem.',
                ], 500);
            }
        }

        return response()->json([
            'ok' => true,
        ], 200);
    }

    /**
     * @return array<string, array{name:string,bytes:string,size_bytes:int,sha256:string,mime:string}>
     */
    private function createVariantImages(string $bytes, string $baseSlug, string $groupKey): array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new \RuntimeException('Image conversion to WebP is not available.');
        }

        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof \GdImage) {
            throw new \RuntimeException('Unable to decode uploaded image.');
        }

        $source = $this->ensureTrueColor($source);

        $variants = [];

        try {
            foreach (self::VARIANT_SPECS as $kind => $spec) {
                $resized = $this->resizeToFit($source, $spec['width'], $spec['height']);
                if ($spec['extension'] === 'ico') {
                    if (! function_exists('imagepng')) {
                        throw new \RuntimeException('Image conversion to ICO is not available.');
                    }
                    $encoded = $this->encodeIco($resized);
                } else {
                    $quality = $spec['quality'];
                    $encoded = $this->encodeWebp($resized, $quality);
                }
                imagedestroy($resized);

                $variants[$kind] = [
                    'name' => $this->variantFileName($baseSlug, $groupKey, $kind, $spec['extension']),
                    'bytes' => $encoded,
                    'size_bytes' => strlen($encoded),
                    'sha256' => hash('sha256', $encoded, false),
                    'mime' => $spec['mime'],
                ];
            }
        } finally {
            imagedestroy($source);
        }

        return $variants;
    }

    private function ensureTrueColor(\GdImage $image): \GdImage
    {
        if (imageistruecolor($image)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);

            return $image;
        }

        $width = max(1, imagesx($image));
        $height = max(1, imagesy($image));

        $trueColor = imagecreatetruecolor($width, $height);
        if (! $trueColor instanceof \GdImage) {
            throw new \RuntimeException('Unable to create image buffer.');
        }

        imagealphablending($trueColor, false);
        imagesavealpha($trueColor, true);
        $transparent = imagecolorallocatealpha($trueColor, 0, 0, 0, 127);
        if (! is_int($transparent)) {
            imagedestroy($trueColor);
            throw new \RuntimeException('Unable to allocate transparent color.');
        }

        if (! imagefill($trueColor, 0, 0, $transparent)) {
            imagedestroy($trueColor);
            throw new \RuntimeException('Unable to initialise image fill.');
        }

        if (! imagecopy($trueColor, $image, 0, 0, 0, 0, $width, $height)) {
            imagedestroy($trueColor);
            throw new \RuntimeException('Unable to normalize uploaded image.');
        }

        imagedestroy($image);

        return $trueColor;
    }

    private function resizeToFit(\GdImage $source, int $targetWidth, int $targetHeight): \GdImage
    {
        $sourceWidth = max(1, imagesx($source));
        $sourceHeight = max(1, imagesy($source));

        $scaleX = $targetWidth / $sourceWidth;
        $scaleY = $targetHeight / $sourceHeight;
        $scale = max(
            (float) min($scaleX, $scaleY, 1.0),
            1.0 / (float) max($sourceWidth, $sourceHeight)
        );

        $scaledWidth = (int) round((float) $sourceWidth * $scale);
        $scaledHeight = (int) round((float) $sourceHeight * $scale);
        $width = max(1, $scaledWidth);
        $height = max(1, $scaledHeight);

        $canvas = imagecreatetruecolor($width, $height);
        if (! $canvas instanceof \GdImage) {
            throw new \RuntimeException('Unable to create resized image buffer.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if (! is_int($transparent)) {
            imagedestroy($canvas);
            throw new \RuntimeException('Unable to allocate transparent color.');
        }

        if (! imagefill($canvas, 0, 0, $transparent)) {
            imagedestroy($canvas);
            throw new \RuntimeException('Unable to prime resized image.');
        }

        if (! imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
            imagedestroy($canvas);
            throw new \RuntimeException('Unable to resize uploaded image.');
        }

        return $canvas;
    }

    private function encodeWebp(\GdImage $image, int $quality): string
    {
        ob_start();
        try {
            if (! imagewebp($image, null, $quality)) {
                throw new \RuntimeException('Unable to encode image as WebP.');
            }
        } finally {
            $encoded = ob_get_clean();
        }

        if (! is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Failed to capture encoded WebP image.');
        }

        return $encoded;
    }

    private function encodeIco(\GdImage $image): string
    {
        $width = max(1, imagesx($image));
        $height = max(1, imagesy($image));

        ob_start();
        try {
            if (! imagepng($image)) {
                throw new \RuntimeException('Unable to encode image as PNG for favicon.');
            }
        } finally {
            $pngData = ob_get_clean();
        }

        if (! is_string($pngData) || $pngData === '') {
            throw new \RuntimeException('Failed to capture PNG bytes for favicon.');
        }

        $pngSize = strlen($pngData);

        $header = pack('v3', 0, 1, 1);
        $entry = pack(
            'CCCCvvVV',
            $width >= 256 ? 0 : ($width & 0xFF),
            $height >= 256 ? 0 : ($height & 0xFF),
            0,
            0,
            0,
            0,
            $pngSize,
            6 + 16
        );

        return $header.$entry.$pngData;
    }

    private function variantFileName(string $baseSlug, string $groupKey, string $kind, string $extension): string
    {
        $suffix = str_replace('_', '-', strtolower($kind));
        if ($kind === 'primary_logo') {
            return sprintf('%s--%s.%s', $baseSlug, $groupKey, $extension);
        }

        return sprintf('%s--%s--%s.%s', $baseSlug, $groupKey, $suffix, $extension);
    }

    /** @return array<string,mixed> */
    private function transform(BrandAsset $asset): array
    {
        /** @var string $id */
        $id = $asset->getAttribute('id');
        /** @var string $kind */
        $kind = $asset->getAttribute('kind');
        /** @var string $name */
        $name = $asset->getAttribute('name');
        /** @var string $mime */
        $mime = $asset->getAttribute('mime');
        /** @var int $size */
        $size = $asset->getAttribute('size_bytes');
        /** @var string $sha */
        $sha = $asset->getAttribute('sha256');
        /** @var mixed $uploaded */
        $uploaded = $asset->getAttribute('uploaded_by_name');
        if (! is_string($uploaded) || $uploaded === '') {
            /** @var mixed $rawId */
            $rawId = $asset->getAttribute('uploaded_by');
            if (is_int($rawId)) {
                $uploaded = 'user:'.(string) $rawId;
            } elseif (is_string($rawId) && $rawId !== '') {
                $uploaded = 'user:'.$rawId;
            } else {
                $uploaded = null;
            }
        }

        /** @var mixed $createdAtRaw */
        $createdAtRaw = $asset->getAttribute('created_at');
        $createdAt = $createdAtRaw instanceof CarbonInterface ? $createdAtRaw : now('UTC');

        return [
            'id' => $id,
            'profile_id' => $asset->getAttribute('profile_id'),
            'kind' => $kind,
            'name' => $name,
            'display_name' => $this->displayNameForAsset($asset, $name),
            'mime' => $mime,
            'size_bytes' => $size,
            'sha256' => Str::lower($sha),
            'uploaded_by' => is_string($uploaded) ? $uploaded : null,
            'created_at' => $createdAt->toJSON(),
        ];
    }

    private function baseFileSlug(string $originalName): string
    {
        /** @var string $base */
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $normalized = preg_replace('/[^a-z0-9]+/i', '-', strtolower($base)) ?? '';
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : 'brand';
    }

    private function displayNameForAsset(BrandAsset $asset, string $storedName): string
    {
        $extension = pathinfo($storedName, PATHINFO_EXTENSION);
        /** @var string $filename */
        $filename = pathinfo($storedName, PATHINFO_FILENAME);
        $parts = explode('--', $filename);
        $base = $parts[0];
        if ($base === '') {
            $base = 'brand';
        }
        $ext = $extension !== '' ? $extension : 'webp';

        return sprintf('%s.%s', $base, $ext);
    }

    private function variantGroupPrefix(string $storedName): ?string
    {
        $filename = pathinfo($storedName, PATHINFO_FILENAME);
        $segments = explode('--', $filename);
        if (count($segments) < 2) {
            return null;
        }

        [$slug, $group] = array_pad($segments, 2, '');

        if ($slug === '' || $group === '') {
            return null;
        }

        return sprintf('%s--%s', $slug, $group);
    }
}
