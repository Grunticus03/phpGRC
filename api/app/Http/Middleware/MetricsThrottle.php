<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class MetricsThrottle
{
    /**
     * @param \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('core.metrics.throttle.enabled', true)) {
            /** @var Response $resp */
            $resp = $next($request);
            return $resp;
        }

        $window = $this->cfgInt('core.metrics.throttle.window_seconds', 60);
        if ($window < 1) {
            $window = 60;
        }
        $limit = $this->cfgInt('core.metrics.throttle.per_minute', 30);
        if ($limit < 1) {
            $limit = 30;
        }

        $subject = $this->subject($request);
        $now     = CarbonImmutable::now('UTC')->getTimestamp();
        $key     = "metrics.throttle:{$subject}";

        $store = $this->repo();

        /** @var array{first:int,count:int}|null $state */
        $state = null;
        /** @var mixed $raw */
        $raw = $store->get($key);
        if (is_array($raw) && isset($raw['first'], $raw['count']) && is_int($raw['first']) && is_int($raw['count'])) {
            $state = ['first' => $raw['first'], 'count' => $raw['count']];
        }
        if ($state === null || ($now - $state['first']) >= $window) {
            $state = ['first' => $now, 'count' => 0];
        }

        $state['count']++;
        $store->put($key, $state, $window);

        $remaining = max(0, $limit - $state['count']);

        if ($state['count'] > $limit) {
            $retryAfter = max(1, $window - ($now - $state['first']));
            return response()->json([
                'ok'          => false,
                'code'        => 'RATE_LIMITED',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After'         => (string) $retryAfter,
                'X-RateLimit-Limit'   => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        /** @var Response $resp */
        $resp = $next($request);
        $resp->headers->set('X-RateLimit-Limit', (string) $limit);
        $resp->headers->set('X-RateLimit-Remaining', (string) $remaining);
        return $resp;
    }

    private function subject(Request $request): string
    {
        $user = $request->user();
        if ($user && method_exists($user, 'getAuthIdentifier')) {
            /** @var mixed $id */
            $id = $user->getAuthIdentifier();
            if (is_int($id) || (is_string($id) && $id !== '')) {
                return 'u:' . (string) $id;
            }
        }
        $ip = $request->ip();
        return 'ip:' . (is_string($ip) && $ip !== '' ? $ip : '0.0.0.0');
    }

    private function repo(): CacheRepository
    {
        try {
            return Cache::store('file');
        } catch (\Throwable) {
            // fall back
        }
        return Cache::store(is_string(config('cache.default')) ? (string) config('cache.default') : 'array');
    }

    private function cfgInt(string $key, int $default): int
    {
        /** @var mixed $v */
        $v = config($key, $default);
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        return $default;
    }
}
