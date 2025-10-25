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
        if ($request->bearerToken() === null) {
            $cookie = $request->cookie(AuthTokenCookie::name());
            if (is_string($cookie) && $cookie !== '') {
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
                Log::debug('TokenCookieGuard injected bearer token from cookie.', [
                    'token_prefix' => substr($decoded, 0, 12),
                ]);
            }
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
