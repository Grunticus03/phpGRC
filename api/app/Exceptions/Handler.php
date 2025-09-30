<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    #[\Override]
    public function render($request, Throwable $e): Response
    {
        // Normalize 429
        if ($e instanceof ThrottleRequestsException || $e instanceof TooManyRequestsHttpException) {
            /** @var array<string, string|int|list<string>> $headers */
            $headers = method_exists($e, 'getHeaders') ? $e->getHeaders() : [];
            $retryAfter = $this->retryAfterFromHeaders($headers);
            $headers['Retry-After'] = (string) $retryAfter;

            return new JsonResponse(
                ['ok' => false, 'code' => 'RATE_LIMITED', 'retry_after' => $retryAfter],
                429,
                $headers
            );
        }

        // Force JSON for API requests
        if ($this->isApi($request)) {
            if ($e instanceof AuthenticationException) {
                return new JsonResponse(['ok' => false, 'code' => 'UNAUTHENTICATED'], 401);
            }
            if ($e instanceof AuthorizationException) {
                return new JsonResponse(['ok' => false, 'code' => 'FORBIDDEN'], 403);
            }
            if ($e instanceof NotFoundHttpException) {
                return new JsonResponse(['ok' => false, 'code' => 'NOT_FOUND'], 404);
            }
            if ($e instanceof MethodNotAllowedHttpException) {
                return new JsonResponse(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], 405);
            }
        }

        return parent::render($request, $e);
    }

    /**
     * @param array<string, string|int|list<string>> $headers
     */
    private function retryAfterFromHeaders(array $headers): int
    {
        $default = 60;

        if (!array_key_exists('Retry-After', $headers)) {
            return $default;
        }

        /** @var string|int|list<string> $raw */
        $raw = $headers['Retry-After'];

        if (is_int($raw)) {
            return max(1, $raw);
        }

        if (is_string($raw)) {
            $n = (int) trim($raw);
            return $n > 0 ? $n : $default;
        }

        // By elimination, $raw is list<string>
        /** @var list<string> $rawList */
        $rawList = $raw;
        $first = $rawList[0] ?? null;
        if (is_string($first)) {
            $n = (int) trim($first);
            return $n > 0 ? $n : $default;
        }

        return $default;
    }

    private function isApi(Request $request): bool
    {
        $path = '/' . ltrim($request->getPathInfo(), '/');
        return str_starts_with($path, '/api/') || $request->expectsJson();
    }
}
