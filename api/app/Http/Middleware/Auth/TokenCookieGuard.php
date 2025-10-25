<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use App\Support\AuthTokenCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class TokenCookieGuard
{
    /**
     * Inject Authorization header from signed cookie when absent.
     *
     * @param  Closure(Request): Response  $next
     */
    /**
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = AuthTokenCookie::name();
        $cookie = $request->cookie($cookieName);
        if ($request->path() === 'auth/me') {
            Log::info('TokenCookieGuard inspecting auth/me request.', [
                'bearer_present' => $request->headers->has('Authorization'),
                'cookie_present' => is_string($cookie) && $cookie !== '',
                'server_header' => $request->server->get('HTTP_AUTHORIZATION'),
            ]);
        }
        if (! is_string($cookie) || $cookie === '') {
            if ($request->bearerToken() === null) {
                Log::info('TokenCookieGuard found no token cookie.', [
                    'path' => $request->path(),
                    'cookies' => array_keys($request->cookies->all()),
                ]);
            }

            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $decoded = rawurldecode($cookie);
        if (! str_contains($decoded, '|')) {
            $decoded = strtr($decoded, ['%7C' => '|', '%7c' => '|']);
        }
        if (str_contains($decoded, ' ')) {
            $decoded = str_replace(' ', '+', $decoded);
        }

        $token = 'Bearer '.trim($decoded);
        $request->headers->set('Authorization', $token);
        $request->server->set('HTTP_AUTHORIZATION', $token);
        Log::info('TokenCookieGuard injected bearer token from cookie.', [
            'token_prefix' => substr($decoded, 0, 12),
            'path' => $request->path(),
        ]);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
