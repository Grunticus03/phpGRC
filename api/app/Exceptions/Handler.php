<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
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

    #[\Override]
    public function register(): void
    {
        // no reporters
    }

    /**
     * Normalize 429 to { ok:false, code:"RATE_LIMITED", retry_after_seconds:int }.
     */
    #[\Override]
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof ThrottleRequestsException || $e instanceof TooManyRequestsHttpException) {
            $headers = method_exists($e, 'getHeaders') ? $e->getHeaders() : [];

            $retryAfter = 60;

            /** @var null|int|string|array<int,string> $retryAfterHeader */
            $retryAfterHeader = $headers['Retry-After'] ?? null;
            if (is_int($retryAfterHeader)) {
                $retryAfter = $retryAfterHeader;
            } elseif (is_string($retryAfterHeader)) {
                $retryAfter = (int) trim($retryAfterHeader);
            } elseif (is_array($retryAfterHeader)) {
                $first = $retryAfterHeader[0] ?? null;
                if (is_string($first)) {
                    $retryAfter = (int) trim($first);
                }
            }

            if ($retryAfter < 1) {
                $retryAfter = 60;
            }
            $headers['Retry-After'] = (string) $retryAfter;

            return new JsonResponse([
                'ok' => false,
                'code' => 'RATE_LIMITED',
                'retry_after_seconds' => $retryAfter,
            ], 429, $headers);
        }

        return parent::render($request, $e);
    }
}
