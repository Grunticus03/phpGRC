<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class GenericRateLimit
{
    /**
     * Reusable limiter for any route.
     *
     * Per-route override:
     *   ->defaults('throttle', ['enabled'=>true,'strategy'=>'ip','window_seconds'=>60,'max_requests'=>30])
     *
     * Fallback config:
     *   core.api.throttle.enabled: bool (default false)
     *   core.api.throttle.strategy: "ip" | "session" | "user"
     *   core.api.throttle.window_seconds: int
     *   core.api.throttle.max_requests: int
     *
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeObj = $request->route();
        if (! $routeObj instanceof Route) {
            /** @var Response $resp */
            $resp = $next($request);

            return $resp;
        }

        /** @var array<string, mixed>|null $routeCfg */
        $routeCfg = (isset($routeObj->defaults['throttle']) && is_array($routeObj->defaults['throttle']))
            ? $routeObj->defaults['throttle']
            : null;

        $enabledDefault = (bool) (config('core.api.throttle.enabled') ?? false);
        $enabled = self::boolOrDefault($routeCfg['enabled'] ?? null, $enabledDefault);
        if (! $enabled) {
            /** @var Response $resp */
            $resp = $next($request);

            return $resp;
        }

        $strategyDefault = is_string(config('core.api.throttle.strategy'))
            ? (string) config('core.api.throttle.strategy')
            : 'ip';
        $strategy = self::stringOrDefault($routeCfg['strategy'] ?? null, $strategyDefault);

        /** @var mixed $winCfg */
        $winCfg = config('core.api.throttle.window_seconds');
        $windowSeconds = self::intOrDefault($routeCfg['window_seconds'] ?? null, is_int($winCfg) ? $winCfg : 60);

        /** @var mixed $maxCfg */
        $maxCfg = config('core.api.throttle.max_requests');
        $maxRequests = self::intOrDefault($routeCfg['max_requests'] ?? null, is_int($maxCfg) ? $maxCfg : 30);

        $windowSeconds = max(1, $windowSeconds);
        $maxRequests = max(1, $maxRequests);

        $key = $this->keyFor($request, $strategy);

        if (! RateLimiter::tooManyAttempts($key, $maxRequests)) {
            RateLimiter::hit($key, $windowSeconds);

            /** @var Response $resp */
            $resp = $next($request);

            /** @var mixed $attemptsRaw */
            $attemptsRaw = RateLimiter::attempts($key);
            $attempts = is_int($attemptsRaw) ? $attemptsRaw : 0;
            $remaining = max(0, $maxRequests - $attempts);

            $resp->headers->set('X-RateLimit-Limit', (string) $maxRequests);
            $resp->headers->set('X-RateLimit-Remaining', (string) $remaining);

            /** @var mixed $availRaw */
            $availRaw = RateLimiter::availableIn($key);
            $retryAfter = is_int($availRaw) ? $availRaw : $windowSeconds;
            $resp->headers->set('Retry-After', (string) max(1, $retryAfter));

            return $resp;
        }

        /** @var mixed $availRaw */
        $availRaw = RateLimiter::availableIn($key);
        $retryAfter = is_int($availRaw) ? $availRaw : $windowSeconds;
        if ($retryAfter < 1) {
            $retryAfter = $windowSeconds;
        }

        $ex = new TooManyRequestsHttpException($retryAfter, 'Too Many Requests');
        $ex->setHeaders([
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $maxRequests,
            'X-RateLimit-Remaining' => '0',
        ]);
        throw $ex;
    }

    private function keyFor(Request $request, string $strategy): string
    {
        // Resolve a stable route URI without triggering analyzer conflicts.
        $routeUri = $request->path();
        /** @var mixed $r */
        $r = $request->route();
        if ($r instanceof Route) {
            $u = $r->uri();
            if ($u !== '') {
                $routeUri = $u;
            }
        }
        if ($routeUri === '') {
            $routeUri = '/';
        }

        $routeSig = sha1($routeUri);
        $key = "grc:rl:ip:0.0.0.0:{$routeSig}";

        switch (strtolower($strategy)) {
            case 'user':
                /** @var \Illuminate\Contracts\Auth\Authenticatable|null $user */
                $user = $request->user();
                /** @var int|string|null $uidRaw */
                $uidRaw = $user?->getAuthIdentifier();
                $uid = (is_int($uidRaw) || is_string($uidRaw)) ? (string) $uidRaw : 'guest';
                $key = "grc:rl:user:{$uid}:{$routeSig}";
                break;

            case 'session':
                $sid = '';
                if ($request->hasSession()) {
                    $sid = $request->session()->getId();
                }
                if ($sid === '') {
                    /** @var mixed $cookieName */
                    $cookieName = config('session.cookie');
                    $cookieKey = is_string($cookieName) && $cookieName !== '' ? $cookieName : null;
                    /** @var mixed $cookieVal */
                    $cookieVal = $cookieKey !== null ? $request->cookie($cookieKey) : null;
                    $sid = is_string($cookieVal) && $cookieVal !== '' ? $cookieVal : 'anon';
                }
                $key = "grc:rl:sess:{$sid}:{$routeSig}";
                break;

            case 'ip':
            default:
                $ip = $request->ip();
                $ipStr = is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
                $key = "grc:rl:ip:{$ipStr}:{$routeSig}";
                break;

        }

        return $key;
    }

    private static function boolOrDefault(mixed $v, bool $default): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            $x = strtolower(trim($v));
            if (in_array($x, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($x, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return $default;
    }

    private static function intOrDefault(mixed $v, int $default): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '' && ctype_digit($v)) {
            return (int) $v;
        }

        return $default;
    }

    private static function stringOrDefault(mixed $v, string $default): string
    {
        return is_string($v) && $v !== '' ? $v : $default;
    }
}
