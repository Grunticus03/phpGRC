<?php

declare(strict_types=1);

namespace App\Http\Controllers\Avatar;

use App\Http\Requests\Avatar\StoreAvatarRequest;
use App\Jobs\Avatar\TranscodeAvatar;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class AvatarController extends Controller
{
    /**
     * POST /api/avatar
     * Accepts multipart/form-data with "file".
     * Phase 4 behavior: dispatch background transcode, keep stub response shape.
     */
    public function store(StoreAvatarRequest $request): JsonResponse
    {
        if (!(bool) config('core.avatars.enabled', true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'AVATAR_NOT_ENABLED',
                'note' => 'stub-only',
            ], 400);
        }

        $file = $request->file('file');
        if (!$file instanceof UploadedFile) {
            return response()->json([
                'ok'   => false,
                'code' => 'AVATAR_NO_FILE',
                'note' => 'stub-only',
            ], 422);
        }

        [$w, $h] = @getimagesize($file->getPathname()) ?: [null, null];
        if (!is_int($w) || !is_int($h)) {
            return response()->json([
                'ok'   => false,
                'code' => 'AVATAR_INVALID_IMAGE',
                'note' => 'stub-only',
            ], 422);
        }

        // Determine target user id: prefer explicit "user_id", else auth user, else 0.
        $userIdParam = $request->input('user_id');
        $userId = is_numeric($userIdParam) ? (int) $userIdParam : $this->authUserId();

        // Queue background transcode + variant generation (32/64/<size_px>).
        try {
            TranscodeAvatar::dispatch(
                $userId,
                $file->getPathname(),
                (int) config('core.avatars.size_px', 128)
            );
            $queued = true;
        } catch (\Throwable) {
            $queued = false;
        }

        return response()->json([
            'ok'    => false,
            'note'  => 'stub-only',
            'queued'=> $queued,
            'file'  => [
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getClientMimeType(),
                'size_bytes'    => $file->getSize(),
                'width'         => $w,
                'height'        => $h,
                'format'        => pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION),
            ],
            'target' => [
                'user_id' => $userId,
                'sizes'   => [32, 64, (int) config('core.avatars.size_px', 128)],
                'format'  => (string) config('core.avatars.format', 'webp'),
            ],
        ], 202);
    }

    /**
     * GET|HEAD /api/avatar/{user}?size=32|64|128
     * Serves WEBP variant from public disk.
     */
    public function show(Request $request, int $user): Response
    {
        if (!(bool) config('core.avatars.enabled', true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'AVATAR_NOT_ENABLED',
            ], 400);
        }

        $allowed = [32, 64, (int) config('core.avatars.size_px', 128)];
        sort($allowed);

        $sizeParam = $request->query('size');
        $size = is_numeric($sizeParam) ? (int) $sizeParam : max($allowed);
        if (!in_array($size, $allowed, true)) {
            $size = max($allowed);
        }

        $disk = Storage::disk('public');
        $rel  = "avatars/{$user}/avatar-{$size}.webp";

        if (!$disk->exists($rel)) {
            return response()->json([
                'ok'    => false,
                'code'  => 'AVATAR_NOT_FOUND',
                'user'  => $user,
                'size'  => $size,
            ], 404);
        }

        $path = $disk->path($rel);
        return response()->file($path, [
            'Content-Type'           => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'public, max-age=3600, immutable',
        ]);
    }

    private function authUserId(): int
    {
        /** @var Authenticatable|null $u */
        $u = Auth::user();
        if ($u && method_exists($u, 'getAuthIdentifier')) {
            $id = $u->getAuthIdentifier();
            if (is_numeric($id)) {
                return (int) $id;
            }
        }
        return 0;
    }
}

