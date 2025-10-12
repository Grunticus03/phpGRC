<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\BrandAssetUploadRequest;
use App\Models\BrandAsset;
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
    public function __construct(private readonly UiSettingsService $settings) {}

    public function index(): JsonResponse
    {
        /** @var array<int, BrandAsset> $assets */
        $assets = BrandAsset::query()
            ->orderByDesc('created_at')
            ->get();

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
            'image/svg+xml',
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

        $sha256 = hash('sha256', $bytes, false);

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

        /** @var mixed $kindInput */
        $kindInput = $request->input('kind', 'primary_logo');
        $kind = is_string($kindInput) ? $kindInput : 'primary_logo';

        /** @var BrandAsset $asset */
        $asset = DB::transaction(function () use ($bytes, $size, $mime, $name, $sha256, $actorId, $actorName, $kind): BrandAsset {
            $record = new BrandAsset([
                'kind' => $kind,
                'name' => $name,
                'mime' => $mime,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'bytes' => $bytes,
                'uploaded_by' => $actorId,
                'uploaded_by_name' => $actorName,
            ]);

            $record->save();

            return $record;
        });

        return response()->json([
            'ok' => true,
            'asset' => $this->transform($asset),
        ], 201);
    }

    public function download(string $assetId): Response
    {
        /** @var BrandAsset|null $asset */
        $asset = BrandAsset::query()->find($assetId);
        if ($asset === null) {
            return response()->noContent(404);
        }

        /** @var mixed $rawBytes */
        $rawBytes = $asset->getAttribute('bytes');
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

        DB::transaction(function () use ($asset): void {
            /** @var mixed $storedId */
            $storedId = $asset->getAttribute('id');
            $assetId = is_string($storedId) ? $storedId : (is_scalar($storedId) ? (string) $storedId : null);
            $asset->delete();
            if (is_string($assetId)) {
                $this->settings->clearBrandAssetReference($assetId);
            }
        });

        return response()->json([
            'ok' => true,
        ], 200);
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
            'kind' => $kind,
            'name' => $name,
            'mime' => $mime,
            'size_bytes' => $size,
            'sha256' => Str::lower($sha),
            'uploaded_by' => is_string($uploaded) ? $uploaded : null,
            'created_at' => $createdAt->toJSON(),
        ];
    }
}
