<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\Auth\RequireSanctumWhenRequired;
use App\Http\Middleware\Auth\TokenCookieGuard;
use App\Http\Middleware\AuthRequired;
use App\Http\Middleware\BreakGlassGuard;
use App\Http\Middleware\GenericRateLimit;
use App\Http\Middleware\MetricsThrottle;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

final class Kernel extends HttpKernel
{
    protected $middlewareAliases = [
        'auth.required' => AuthRequired::class,
        'auth.cookie' => TokenCookieGuard::class,
        'auth.require_sanctum' => RequireSanctumWhenRequired::class,
        'breakglass.guard' => BreakGlassGuard::class,
        'metrics.throttle' => MetricsThrottle::class,
        'limit' => GenericRateLimit::class,
    ];

    /**
     * Ensure token cookies promote to Authorization headers before auth checks run.
     */
    protected $middlewarePriority = [
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        TokenCookieGuard::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
        \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];
}
