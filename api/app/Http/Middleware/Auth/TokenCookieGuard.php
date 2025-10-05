<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use App\Support\AuthTokenCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TokenCookieGuard
{
    /**
     * Inject Authorization header from signed cookie when absent.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            $cookie = $request->cookie(AuthTokenCookie::name());
            if (is_string($cookie) && $cookie !== '') {
                $token = 'Bearer ' . trim($cookie);
                $request->headers->set('Authorization', $token);
                $request->server->set('HTTP_AUTHORIZATION', $token);
            }
        }

        return $next($request);
    }
}
