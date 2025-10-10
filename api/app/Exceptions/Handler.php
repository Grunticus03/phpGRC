<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class Handler extends ExceptionHandler
{
    /** @var array<int, class-string<Throwable>> */
    protected $dontReport = [];

    /** @var array<int, string> */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /** @override */
    #[\Override]
    public function register(): void
    {
        // no reporters
    }

    /** @override */
    #[\Override]
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401);
    }

    /** @override */
    #[\Override]
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof PostTooLargeException) {
            $limitMb = $this->configuredEvidenceLimitMb();
            $label = $limitMb !== null ? sprintf('%d MB', $limitMb) : $this->maxUploadSizeLabel();

            return new JsonResponse([
                'ok' => false,
                'code' => 'UPLOAD_TOO_LARGE',
                'message' => 'Upload greater than '.$label,
            ], 413);
        }

        if ($e instanceof ThrottleRequestsException || $e instanceof TooManyRequestsHttpException) {
            /** @var array<string,mixed> $headers */
            $headers = $e->getHeaders();

            $retryAfter = 60;

            /** @var int|string|array<int,string>|null $ra */
            $ra = $headers['Retry-After'] ?? null;
            if (is_int($ra)) {
                $retryAfter = $ra;
            } elseif (is_string($ra)) {
                $retryAfter = (int) trim($ra);
            } elseif (is_array($ra) && $ra !== []) {
                $retryAfter = (int) trim($ra[0]);
            }

            if ($retryAfter < 1) {
                $retryAfter = 60;
            }
            $headers['Retry-After'] = (string) $retryAfter;

            return new JsonResponse([
                'ok' => false,
                'code' => 'RATE_LIMITED',
                'retry_after' => $retryAfter,
            ], 429, $headers);
        }

        return parent::render($request, $e);
    }

    private function maxUploadSizeLabel(): string
    {
        $bytes = $this->maxUploadSizeBytes();
        if ($bytes === null) {
            return 'the configured limit';
        }

        return $this->formatBytes($bytes);
    }

    private function maxUploadSizeBytes(): ?int
    {
        $limits = [];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            /** @var string|false $raw */
            $raw = ini_get($key);
            if (! is_string($raw)) {
                continue;
            }
            $trimmed = trim($raw);
            if ($trimmed === '') {
                continue;
            }
            $bytes = $this->parseIniSizeToBytes($trimmed);
            if ($bytes === null || $bytes <= 0) {
                continue;
            }
            $limits[] = $bytes;
        }

        if ($limits === []) {
            return null;
        }

        sort($limits);

        return $limits[0];
    }

    private function parseIniSizeToBytes(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $number = $value;
        $unit = '';

        if (! is_numeric($value)) {
            $unit = strtolower(substr($value, -1));
            $number = substr($value, 0, -1);
        }

        if (! is_numeric($number)) {
            return null;
        }

        $base = (float) $number;
        if ($base <= 0) {
            return null;
        }

        $multiplier = match ($unit) {
            'k' => 1024,
            'm' => 1024 * 1024,
            'g' => 1024 * 1024 * 1024,
            't' => 1024 * 1024 * 1024 * 1024,
            'p' => 1024 * 1024 * 1024 * 1024 * 1024,
            default => 1,
        };

        return (int) floor($base * (float) $multiplier);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d bytes', $bytes);
        }

        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;

        foreach ($units as $unit) {
            $value /= 1024.0;
            if ($value < 1024) {
                return sprintf('%s %s', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'), $unit);
            }
        }

        return sprintf('%d bytes', $bytes);
    }

    private function configuredEvidenceLimitMb(): ?int
    {
        /** @var mixed $raw */
        $raw = config('core.evidence.max_mb');
        if (is_numeric($raw)) {
            $value = (int) $raw;
            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }
}
