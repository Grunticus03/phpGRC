<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LocalAuthEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->configBoolean(config('core.auth.local.enabled', true))) {
            return response()->json([
                'ok' => false,
                'code' => 'LOCAL_AUTH_DISABLED',
            ], 403);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function configBoolean(mixed $raw): bool
    {
        if ($raw === null) {
            return false;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw)) {
            return $raw !== 0;
        }

        if (is_float($raw)) {
            return (int) $raw !== 0;
        }

        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));
            if ($normalized === '') {
                return false;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $raw;
    }
}
