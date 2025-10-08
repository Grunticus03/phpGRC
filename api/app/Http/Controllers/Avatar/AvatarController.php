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
        $enabled = self::boolFrom(config('core.avatars.enabled'), true);
        if (! $enabled) {
            return response()->json([
                'ok' => false,
                'code' => 'AVATAR_NOT_ENABLED',
                'note' => 'stub-only',
            ], 400);
        }

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json([
                'ok' => false,
                'code' => 'AVATAR_NO_FILE',
                'note' => 'stub-only',
            ], 422);
        }

        [$w, $h] = @getimagesize($file->getPathname()) ?: [null, null];
        if (! is_int($w) || ! is_int($h)) {
            return response()->json([
                'ok' => false,
                'code' => 'AVATAR_INVALID_IMAGE',
                'note' => 'stub-only',
            ], 422);
        }

        /** @var mixed $userIdParamRaw */
        $userIdParamRaw = $request->input('user_id');
        $userId = is_int($userIdParamRaw)
            ? $userIdParamRaw
            : ((is_string($userIdParamRaw) && ctype_digit($userIdParamRaw)) ? (int) $userIdParamRaw : $this->authUserId());

        $sizePx = self::intFrom(config('core.avatars.size_px'), 128);
        $format = strtolower(self::stringFrom(config('core.avatars.format'), 'webp'));

        try {
            TranscodeAvatar::dispatch(
                $userId,
                $file->getPathname(),
                $sizePx
            );
            $queued = true;
        } catch (\Throwable) {
            $queued = false;
        }

        $mimeOut = $file->getClientMimeType();

        return response()->json([
            'ok' => false,
            'note' => 'stub-only',
            'queued' => $queued,
            'file' => [
                'original_name' => $file->getClientOriginalName(),
                'mime' => $mimeOut,
                'size_bytes' => $file->getSize(),
                'width' => $w,
                'height' => $h,
                'format' => pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION),
            ],
            'target' => [
                'user_id' => $userId,
                'sizes' => [32, 64, $sizePx],
                'format' => $format,
            ],
        ], 202);
    }

    /**
     * GET|HEAD /api/avatar/{user}?size=32|64|128
     * Serves WEBP variant from public disk.
     */
    public function show(Request $request, int $user): Response
    {
        $enabled = self::boolFrom(config('core.avatars.enabled'), true);
        if (! $enabled) {
            return response()->json([
                'ok' => false,
                'code' => 'AVATAR_NOT_ENABLED',
            ], 400);
        }

        $sizePx = self::intFrom(config('core.avatars.size_px'), 128);
        $allowed = [32, 64, $sizePx];
        sort($allowed);

        $sizeParam = $request->query('size');
        $size = (is_scalar($sizeParam) && is_numeric($sizeParam)) ? (int) $sizeParam : max($allowed);
        if (! in_array($size, $allowed, true)) {
            $size = max($allowed);
        }

        $disk = Storage::disk('public');
        $rel = "avatars/{$user}/avatar-{$size}.webp";

        if (! $disk->exists($rel)) {
            return response()->json([
                'ok' => false,
                'code' => 'AVATAR_NOT_FOUND',
                'user' => $user,
                'size' => $size,
            ], 404);
        }

        $path = $disk->path($rel);

        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600, immutable',
        ]);
    }

    private function authUserId(): int
    {
        /** @var Authenticatable|null $u */
        $u = Auth::user();
        if ($u !== null) {
            /** @var mixed $rawId */
            $rawId = $u->getAuthIdentifier();
            if (is_int($rawId)) {
                return $rawId;
            }
            if (is_string($rawId) && ctype_digit($rawId)) {
                return (int) $rawId;
            }
        }

        return 0;
    }

    private static function boolFrom(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $v = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return $v ?? $default;
        }

        return $default;
    }

    private static function intFrom(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $t = trim($value);
            if ($t !== '' && preg_match('/^-?\d+$/', $t) === 1) {
                return (int) $t;
            }
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return $default;
    }

    private static function stringFrom(mixed $value, string $default = ''): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_scalar($value)) {
            $s = (string) $value;

            return $s !== '' ? $s : $default;
        }

        return $default;
    }
}
