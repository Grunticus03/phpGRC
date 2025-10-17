<?php

declare(strict_types=1);

namespace App\Http\Controllers\Branding;

use App\Models\BrandAsset;
use App\Services\Settings\BrandAssetStorageService;
use App\Services\Settings\UiSettingsService;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class FaviconController extends Controller
{
    public function __construct(
        private readonly UiSettingsService $settings,
        private readonly BrandAssetStorageService $storage
    ) {}

    public function __invoke(): Response
    {
        $profile = $this->settings->activeBrandProfile();
        $brand = $this->settings->brandProfileAsConfig($profile);
        $faviconId = $brand['favicon_asset_id'] ?? null;
        if (! is_string($faviconId) || $faviconId === '') {
            return response()->noContent(404);
        }

        /** @var BrandAsset|null $asset */
        $asset = BrandAsset::query()->find($faviconId);
        if ($asset === null) {
            return response()->noContent(404);
        }

        $bytes = $this->storage->readAsset($asset);
        if (! is_string($bytes) || $bytes === '') {
            /** @var mixed $fallback */
            $fallback = $asset->getAttribute('bytes');
            if (! is_string($fallback) || $fallback === '') {
                return response()->noContent(404);
            }
            $bytes = $fallback;
        }

        /** @var mixed $mimeRaw */
        $mimeRaw = $asset->getAttribute('mime');
        $mime = is_string($mimeRaw) && $mimeRaw !== '' ? $mimeRaw : 'image/x-icon';
        $size = strlen($bytes);
        /** @var mixed $shaRaw */
        $shaRaw = $asset->getAttribute('sha256');
        $etag = is_string($shaRaw) && $shaRaw !== '' ? sprintf('W/"favicon:%s"', $shaRaw) : null;

        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Cache-Control' => 'public, max-age=3600, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        return response($bytes, 200, $headers);
    }
}
