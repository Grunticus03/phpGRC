<?php

declare(strict_types=1);

namespace App\Services\Avatar;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class AvatarProcessor
{
    /**
     * Transcode source image to WEBP and write multiple square variants.
     *
     * @param  list<int>  $sizes
     */
    public function process(int $userId, string $sourcePath, array $sizes): void
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('Source image not readable.');
        }

        $data = file_get_contents($sourcePath);
        if ($data === false) {
            throw new RuntimeException('Failed to read source image.');
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new RuntimeException('GD with WEBP support is required.');
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            throw new RuntimeException('Unsupported image data.');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $side = min($srcW, $srcH);

        // Center-crop to square
        $srcX = (int) max(0, ($srcW - $side) / 2);
        $srcY = (int) max(0, ($srcH - $side) / 2);

        $disk = Storage::disk('public');
        $baseDir = "avatars/{$userId}";
        $disk->makeDirectory($baseDir);

        foreach (array_unique($sizes) as $size) {
            if ($size < 16 || $size > 1024) {
                continue;
            }

            $dst = imagecreatetruecolor($size, $size);
            if ($dst === false) {
                continue;
            }

            imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $size, $size, $side, $side);

            $tmp = tmpfile();
            if ($tmp === false) {
                imagedestroy($dst);

                continue;
            }

            /** @var array{uri:string} $meta */
            $meta = stream_get_meta_data($tmp);
            $tmpPath = $meta['uri'];

            if (! imagewebp($dst, $tmpPath, 80)) {
                fclose($tmp);
                imagedestroy($dst);

                continue;
            }

            $rel = "{$baseDir}/avatar-{$size}.webp";
            $bytes = @file_get_contents($tmpPath);
            $disk->put($rel, $bytes !== false ? $bytes : '', 'public');

            fclose($tmp);
            imagedestroy($dst);
        }

        imagedestroy($src);
    }
}
