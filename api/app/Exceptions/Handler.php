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
    protected function unauthenticated($request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401);
    }

    /** @override */
    #[\Override]
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof ThrottleRequestsException || $e instanceof TooManyRequestsHttpException) {
            /** @var array<string,mixed> $headers */
            $headers = method_exists($e, 'getHeaders') ? $e->getHeaders() : [];

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
                'ok'          => false,
                'code'        => 'RATE_LIMITED',
                'retry_after' => $retryAfter,
            ], 429, $headers);
        }

        return parent::render($request, $e);
    }
}
