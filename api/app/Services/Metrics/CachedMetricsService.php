<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Metrics snapshot with lightweight caching that does NOT require a DB cache table.
 * - Reads adjustable settings from the database via SettingsService (core.metrics.*).
 * - Uses the configured cache store (default/file) in production, array store in tests.
 */
final class CachedMetricsService
{
    public function __construct(
        private readonly AuthActivityCalculator $authActivity,
        private readonly EvidenceMimeBreakdownCalculator $evidenceMime,
        private readonly AdminActivityCalculator $adminActivity,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Back-compat helper. Returns only the data payload.
     *
     * @return array{
     *   auth_activity: array{
     *     window_days:int,
     *     from: non-empty-string,
     *     to: non-empty-string,
     *     daily:list<array{date:non-empty-string,success:int,failed:int,total:int}>,
     *     totals:array{success:int,failed:int,total:int},
     *     max_daily_total:int
     *   },
     *   evidence_mime: array{
     *     total:int,
     *     by_mime:list<array{mime:non-empty-string,count:int,percent:float}>
     *   },
     *   admin_activity: array{
     *     admins:list<array{id:int,name:string,email:string,last_login_at:string|null}>
     *   }
     * }
     */
    public function snapshot(?int $authWindowDays = null): array
    {
        $out = $this->snapshotWithMeta($authWindowDays);

        return $out['data'];
    }

    /**
     * Produce the dashboard metrics snapshot and return cache meta.
     *
     * @return array{
     *   data: array{
     *     auth_activity: array{
     *       window_days:int,
     *       from: non-empty-string,
     *       to: non-empty-string,
     *       daily:list<array{date:non-empty-string,success:int,failed:int,total:int}>,
     *       totals:array{success:int,failed:int,total:int},
     *       max_daily_total:int
     *     },
     *     evidence_mime: array{
     *       total:int,
     *       by_mime:list<array{mime:non-empty-string,count:int,percent:float}>
     *     },
     *     admin_activity: array{
     *       admins:list<array{id:int,name:string,email:string,last_login_at:string|null}>
     *     }
     *   },
     *   cache: array{ttl:int, hit:bool}
     * }
     */
    public function snapshotWithMeta(?int $authWindowDays = null): array
    {
        $defaults = $this->loadDefaults(); // [auth_days:int, ttl:int]

        $authDays = $this->clampAuthDays($authWindowDays ?? $defaults['auth_days']);
        $ttl = $this->clampTtlAllowZero($defaults['ttl']); // 0 disables

        $key = sprintf('metrics.snapshot.v2:auth=%d', $authDays);

        $store = $this->cacheStore();

        if ($ttl <= 0) {
            $store->forget($key);
        }

        if ($ttl > 0 && $store->has($key)) {
            /** @var mixed $raw */
            $raw = $store->get($key);
            if (is_array($raw)) {
                /** @var array{
                 *   auth_activity: array{
                 *     window_days:int,
                 *     from: non-empty-string,
                 *     to: non-empty-string,
                 *     daily:list<array{date:non-empty-string,success:int,failed:int,total:int}>,
                 *     totals:array{success:int,failed:int,total:int},
                 *     max_daily_total:int
                 *   },
                 *   evidence_mime: array{
                 *     total:int,
                 *     by_mime:list<array{mime:non-empty-string,count:int,percent:float}>
                 *   },
                 *   admin_activity: array{
                 *     admins:list<array{id:int,name:string,email:string,last_login_at:string|null}>
                 *   }
                 * } $cachedPayload
                 */
                $cachedPayload = $raw;

                return ['data' => $cachedPayload, 'cache' => ['ttl' => $ttl, 'hit' => true]];
            }
        }

        /** @var array{
         *   window_days:int,
         *   from: non-empty-string,
         *   to: non-empty-string,
         *   daily:list<array{date:non-empty-string,success:int,failed:int,total:int}>,
         *   totals:array{success:int,failed:int,total:int},
         *   max_daily_total:int
         * } $auth
         */
        $auth = $this->authActivity->compute($authDays);

        /** @var array{
         *   total:int,
         *   by_mime:list<array{mime:non-empty-string,count:int,percent:float}>
         * } $mime
         */
        $mime = $this->evidenceMime->compute();

        /** @var array{
         *   admins:list<array{id:int,name:string,email:string,last_login_at:string|null}>
         * } $admins
         */
        $admins = $this->adminActivity->compute();

        /** @var array{
         *   auth_activity: array{
         *     window_days:int,
         *     from: non-empty-string,
         *     to: non-empty-string,
         *     daily:list<array{date:non-empty-string,success:int,failed:int,total:int}>,
         *     totals:array{success:int,failed:int,total:int},
         *     max_daily_total:int
         *   },
         *   evidence_mime: array{
         *     total:int,
         *     by_mime:list<array{mime:non-empty-string,count:int,percent:float}>
         *   },
         *   admin_activity: array{
         *     admins:list<array{id:int,name:string,email:string,last_login_at:string|null}>
         *   }
         * } $payload
         */
        $payload = [
            'auth_activity' => $auth,
            'evidence_mime' => $mime,
            'admin_activity' => $admins,
        ];

        if ($ttl > 0) {
            $store->put($key, $payload, now()->addSeconds($ttl));
        }

        return ['data' => $payload, 'cache' => ['ttl' => $ttl, 'hit' => false]];
    }

    /**
     * Read DB-backed defaults from SettingsService with hard fallbacks.
     *
     * @return array{auth_days:int, ttl:int}
     */
    private function loadDefaults(): array
    {
        /** @var array<string,mixed> $eff */
        $eff = $this->settings->effectiveConfig();

        /** @var array<string,mixed> $metrics */
        $metrics = [];
        if (isset($eff['core']) && is_array($eff['core']) && isset($eff['core']['metrics']) && is_array($eff['core']['metrics'])) {
            $metrics = $eff['core']['metrics'];
        }

        $authDays = 7;
        if (isset($metrics['rbac_denies']) && is_array($metrics['rbac_denies']) && isset($metrics['rbac_denies']['window_days'])) {
            $authDays = $this->clampAuthDays($metrics['rbac_denies']['window_days']);
        }

        /** @var mixed $ttlCandidate */
        $ttlCandidate = $metrics['cache_ttl_seconds'] ?? config('core.metrics.cache_ttl_seconds');
        /** @var int|float|string|null $ttlSource */
        $ttlSource = is_array($ttlCandidate) ? 0 : $ttlCandidate;
        $ttl = $this->clampTtlAllowZero($ttlSource); // 0 disables

        return [
            'auth_days' => $authDays,
            'ttl' => $ttl,
        ];
    }

    private function clampAuthDays(mixed $n): int
    {
        $v = is_int($n) ? $n : (is_string($n) && ctype_digit($n) ? (int) $n : 0);
        if ($v < 7) {
            $v = 7;
        }
        if ($v > 365) {
            $v = 365;
        }

        return $v;
    }

    private function clampTtlAllowZero(mixed $n): int
    {
        $v = is_int($n) ? $n : (is_string($n) && ctype_digit($n) ? (int) $n : -1);
        if ($v < 0) {
            $v = 0;
        }      // 0 disables
        if ($v > 3600) {
            $v = 3600;
        }

        return $v;
    }

    private function cacheStore(): Repository
    {
        if (app()->environment('testing')) {
            return Cache::store('array');
        }

        /** @var mixed $preferredConfig */
        $preferredConfig = config('core.metrics.cache_store');
        $preferred = is_string($preferredConfig) ? trim($preferredConfig) : '';
        if ($preferred !== '') {
            try {
                return Cache::store($preferred);
            } catch (\Throwable) {
                // fall through to defaults
            }
        }

        /** @var mixed $defaultConfig */
        $defaultConfig = config('cache.default', 'file');
        $default = is_string($defaultConfig) ? trim($defaultConfig) : 'file';
        if ($default !== '') {
            try {
                return Cache::store($default);
            } catch (\Throwable) {
            }
        }

        try {
            return Cache::store('file');
        } catch (\Throwable) {
            return Cache::store('array');
        }
    }
}
