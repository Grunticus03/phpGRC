<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
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
    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];

    #[\Override]
    public function register(): void
    {
        // no reporters
    }

    #[\Override]
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated'], 401);
    }

    /**
     * Normalize 429 to { ok:false, code:"RATE_LIMITED", retry_after:int }.
     */
    #[\Override]
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof ThrottleRequestsException || $e instanceof TooManyRequestsHttpException) {
            /** @var array<string, int|string|string[]> $headers */
            $headers = method_exists($e, 'getHeaders') ? $e->getHeaders() : [];

            $retryAfter = 60;

            /** @var null|int|string|array<int,string> $h */
            $h = $headers['Retry-After'] ?? null;
            if (is_int($h)) {
                $retryAfter = $h;
            } elseif (is_string($h)) {
                $retryAfter = (int) trim($h);
            } elseif (is_array($h) && isset($h[0])) {
                $retryAfter = (int) trim($h[0]);
            }

            if ($retryAfter < 1) {
                $retryAfter = 60;
            }
            $headers['Retry-After'] = (string) $retryAfter;

            return new JsonResponse([
                'ok'          => false,
                'code'        => 'RATE_LIMITED',
                'retry_after' => $retryAfter,
            ], 429, $headers);
        }

        return parent::render($request, $e);
    }
}
