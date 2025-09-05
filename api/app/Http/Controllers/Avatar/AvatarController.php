<?php

declare(strict_types=1);

namespace App\Http\Controllers\Avatar;

use App\Http\Requests\Avatar\StoreAvatarRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class AvatarController extends Controller
{
    /**
     * POST /api/avatar
     * Accepts multipart/form-data with "file".
     * Validates MIME and dimensions against core.avatars.*.
     * No storage in Phase 4.
     */
    public function store(StoreAvatarRequest $request): JsonResponse
    {
        if (! (bool) config('core.avatars.enabled', true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'AVATARS_NOT_ENABLED',
                'note' => 'stub-only',
            ], 400);
        }

        $file = $request->file('file'); // already validated to be an image of allowed types

        // Dimension check against config size_px
        $maxPx = (int) config('core.avatars.size_px', 128);
        [$w, $h] = @getimagesize($file->getPathname()) ?: [null, null];

        if (!is_int($w) || !is_int($h)) {
            return response()->json([
                'ok'   => false,
                'code' => 'AVATAR_INVALID_IMAGE',
                'note' => 'stub-only',
            ], 422);
        }

        if ($w > ($maxPx * 2) || $h > ($maxPx * 2)) {
            return response()->json([
                'ok'     => false,
                'code'   => 'AVATAR_TOO_LARGE',
                'limit'  => ['max_width' => $maxPx * 2, 'max_height' => $maxPx * 2],
                'actual' => ['width' => $w, 'height' => $h],
                'note'   => 'stub-only',
            ], 422);
        }

        // Echo-only metadata
        return response()->json([
            'ok'   => false,
            'note' => 'stub-only',
            'file' => [
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getClientMimeType(),
                'size_bytes'    => $file->getSize(),
                'width'         => $w,
                'height'        => $h,
                'format'        => pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION),
            ],
        ]);
    }
}
