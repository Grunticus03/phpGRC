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
     * Validates MIME against core.avatars.format (WEBP). No storage in Phase 4.
     */
    public function store(StoreAvatarRequest $request): JsonResponse
    {
        if (! (bool) config('core.avatars.enabled', true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'AVATAR_NOT_ENABLED',
                'note' => 'stub-only',
            ], 400);
        }

        $file = $request->file('file');

        [$w, $h] = @getimagesize($file->getPathname()) ?: [null, null];
        if (!is_int($w) || !is_int($h)) {
            return response()->json([
                'ok'   => false,
                'code' => 'AVATAR_INVALID_IMAGE',
                'note' => 'stub-only',
            ], 422);
        }

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
        ], 202);
    }
}
