<?php
declare(strict_types=1);

namespace App\Http\Middleware\Auth;

use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class BruteForceGuard
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('core.auth.bruteforce.enabled', false)) {
            /** @var Response $resp */
            $resp = $next($request);
            return $resp;
        }

        $strategy      = $this->cfgString('core.auth.bruteforce.strategy', 'session'); // 'session'|'ip'
        $windowSeconds = max(1, $this->cfgInt('core.auth.bruteforce.window_seconds', 900));
        $maxAttempts   = max(1, $this->cfgInt('core.auth.bruteforce.max_attempts', 5));
        $lockStatus    = $this->cfgInt('core.auth.bruteforce.lock_http_status', 429);
        $cookieName    = $this->cfgString('core.auth.session_cookie.name', 'phpgrc_auth_attempt');

        $now = CarbonImmutable::now('UTC')->getTimestamp();

        /** @var string|null $subject */
        $subject = null;
        /** @var string|null $setCookieValue */
        $setCookieValue = null;

        if ($strategy === 'ip') {
            $ip = $request->ip();
            $subject = is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
        } else {
            // Try to read existing cookie.
            /** @var mixed $existing */
            $existing = $request->cookie($cookieName);
            if (!is_string($existing) || $existing === '') {
                /** @var mixed $alt */
                $alt = $request->cookies->get($cookieName);
                $existing = is_string($alt) ? $alt : '';
            }
            if ($existing === '') {
                $raw = (string) $request->headers->get('Cookie', '');
                if ($raw !== '') {
                    $pattern = '/(?:^|;\s*)' . preg_quote($cookieName, '/') . '=([^;]+)/';
                    if (preg_match($pattern, $raw, $m) === 1) {
                        $val = urldecode($m[1]);
                        if ($val !== '') {
                            $existing = $val;
                        }
                    }
                }
            }

            if ($existing !== '') {
                $subject = $existing;
            } else {
                // Deterministic fallback: use IP so attempts aggregate before the cookie exists.
                $ip = $request->ip();
                $subject = is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
                $setCookieValue = $subject; // set for next requests too
                // Also reflect into current request so downstream can read it if needed.
                $request->cookies->set($cookieName, $subject);
            }
        }

        $cache   = $this->cacheRepo();
        $cacheKey = "auth_bf:{$strategy}:{$subject}";

        /** @var array{first:int,count:int}|null $state */
        $state = null;
        /** @var mixed $cached */
        $cached = $cache->get($cacheKey);
        if (is_array($cached)
            && array_key_exists('first', $cached) && is_int($cached['first'])
            && array_key_exists('count', $cached) && is_int($cached['count'])
        ) {
            $state = ['first' => $cached['first'], 'count' => $cached['count']];
        }
        if ($state === null || ($now - $state['first']) > $windowSeconds) {
            $state = ['first' => $now, 'count' => 0];
        }

        $state['count']++;
        $cache->put($cacheKey, $state, $windowSeconds);

        if ($state['count'] >= $maxAttempts) {
            $retryAfter = max(1, $windowSeconds - ($now - $state['first']));

            $this->auditAuth('auth.login.locked', $request, [
                'strategy'       => $strategy,
                'attempts'       => $state['count'],
                'window_seconds' => $windowSeconds,
            ]);

            $resp = response()->json([
                'ok'          => false,
                'code'        => 'AUTH_LOCKED',
                'strategy'    => $strategy,
                'retry_after' => $retryAfter,
            ], $lockStatus)->withHeaders(['Retry-After' => (string) $retryAfter]);

            if ($setCookieValue !== null) {
                $resp->withCookie(cookie(
                    $cookieName,
                    $setCookieValue,
                    max(1, (int) ceil($windowSeconds / 60)),
                    '/',
                    null,
                    (bool) config('session.secure', false),
                    true,
                    false,
                    'lax'
                ));
            }

            return $resp;
        }

        // Not locked
        $this->auditAuth('auth.login.failed', $request, [
            'strategy'       => $strategy,
            'attempts'       => $state['count'],
            'window_seconds' => $windowSeconds,
        ]);

        /** @var Response $resp */
        $resp = $next($request);

        if ($setCookieValue !== null) {
            $resp->headers->setCookie(cookie(
                $cookieName,
                $setCookieValue,
                max(1, (int) ceil($windowSeconds / 60)),
                '/',
                null,
                (bool) config('session.secure', false),
                true,
                false,
                'lax'
            ));
        }

        return $resp;
    }

    /**
     * @param non-empty-string $action
     * @param array<string,mixed> $meta
     */
    private function auditAuth(string $action, Request $request, array $meta): void
    {
        try {
            if (!config('core.audit.enabled', true) || !Schema::hasTable('audit_events')) {
                return;
            }

            $this->audit->log([
                'actor_id'    => null,
                'action'      => $action,
                'category'    => 'AUTH',
                'entity_type' => 'auth',
                'entity_id'   => 'login',
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => $meta,
            ]);
        } catch (\Throwable) {
            // no-op
        }
    }

    /**
     * Prefer a persistent store during tests; gracefully avoid DB store if table missing.
     */
    private function cacheRepo(): CacheRepository
    {
        try {
            return Cache::store('file');
        } catch (\Throwable) {
            // fall back below
        }

        $default = is_string(config('cache.default')) ? (string) config('cache.default') : 'array';
        $repo = Cache::store($default);

        try {
            if ($repo->getStore() instanceof \Illuminate\Cache\DatabaseStore && !Schema::hasTable('cache')) {
                return Cache::store('array');
            }
        } catch (\Throwable) {
            return Cache::store('array');
        }

        return $repo;
    }

    /**
     * @psalm-param non-empty-string $default
     * @psalm-return non-empty-string
     */
    private function cfgString(string $key, string $default): string
    {
        /** @var mixed $v */
        $v = config($key, $default);
        if (!is_string($v) || $v === '') {
            /** @var non-empty-string $default */
            return $default;
        }
        /** @var non-empty-string $v */
        return $v;
    }

    private function cfgInt(string $key, int $default): int
    {
        /** @var mixed $v */
        $v = config($key, $default);
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }
        return $default;
    }
}

