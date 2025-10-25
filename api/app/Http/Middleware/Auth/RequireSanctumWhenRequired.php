<?php

declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use App\Support\ConfigBoolean;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RequireSanctumWhenRequired
{
    /**
     * Enforce Sanctum authentication only when RBAC requires it.
     *
     * @param  Closure(Request):Response  $next
     *
     * @throws AuthenticationException
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! ConfigBoolean::value('core.rbac.require_auth', false)) {
            return $next($request);
        }

        Auth::shouldUse('sanctum');

        if (Auth::guard('sanctum')->check()) {
            return $next($request);
        }

        logger()->warning('RequireSanctumWhenRequired rejected request.', [
            'path' => $request->path(),
            'bearer_prefix' => substr((string) $request->bearerToken(), 0, 12),
        ]);

        throw new AuthenticationException;
    }
}
