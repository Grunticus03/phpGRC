<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Services\Metrics\EvidenceFreshnessCalculator;
use App\Services\Metrics\RbacDeniesCalculator;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * Metrics snapshot with lightweight caching that does NOT require a DB cache table.
 * - Reads adjustable settings from the database via SettingsService (core.metrics.*).
 * - Uses the in-memory "array" cache store to avoid DB during tests.
 */
final class CachedMetricsService
{
    private RbacDeniesCalculator $rbacDenies;
    private EvidenceFreshnessCalculator $evidenceFreshness;
    private SettingsService $settings;

    public function __construct(
        RbacDeniesCalculator $rbacDenies,
        EvidenceFreshnessCalculator $evidenceFreshness,
        SettingsService $settings
    ) {
        $this->rbacDenies = $rbacDenies;
        $this->evidenceFreshness = $evidenceFreshness;
        $this->settings = $settings;
    }

    /**
     * Back-compat. Returns only the data payload.
     *
     * @param int|null $deniesWindowDays
     * @param int|null $freshnessDays
     * @return array{
     *   rbac_denies: array{
     *     window_days:int,
     *     from: non-empty-string,
     *     to: non-empty-string,
     *     denies:int,
     *     total:int,
     *     rate:float,
     *     daily: list<array{date: non-empty-string, denies:int, total:int, rate:float}>
     *   },
     *   evidence_freshness: array{
     *     days:int,
     *     total:int,
     *     stale:int,
     *     percent:float,
     *     by_mime: list<array{mime: non-empty-string, total:int, stale:int, percent:float}>
     *   }
     * }
     */
    public function snapshot(?int $deniesWindowDays = null, ?int $freshnessDays = null): array
    {
        /** @var array{
         *   data: array{
         *     rbac_denies: array{
         *       window_days:int,
         *       from: non-empty-string,
         *       to: non-empty-string,
         *       denies:int,
         *       total:int,
         *       rate:float,
         *       daily: list<array{date: non-empty-string, denies:int, total:int, rate:float}>
         *     },
         *     evidence_freshness: array{
         *       days:int,
         *       total:int,
         *       stale:int,
         *       percent:float,
         *       by_mime: list<array{mime: non-empty-string, total:int, stale:int, percent:float}>
         *     }
         *   },
         *   cache: array{ttl:int, hit:bool}
         * } $out
         */
        $out = $this->snapshotWithMeta($deniesWindowDays, $freshnessDays);

        /** @var array{
         *   rbac_denies: array{
         *     window_days:int,
         *     from: non-empty-string,
         *     to: non-empty-string,
         *     denies:int,
         *     total:int,
         *     rate:float,
         *     daily: list<array{date: non-empty-string, denies:int, total:int, rate:float}>
         *   },
         *   evidence_freshness: array{
         *     days:int,
         *     total:int,
         *     stale:int,
         *     percent:float,
         *     by_mime: list<array{mime: non-empty-string, total:int, stale:int, percent:float}>
         *   }
         * } $data
         */
        $data = $out['data'];

        return $data;
    }

    /**
     * Produce the dashboard metrics snapshot and return cache meta.
     *
     * @param int|null $deniesWindowDays Optional override for RBAC denies window (1..365). Null uses DB default.
     * @param int|null $freshnessDays    Optional override for Evidence freshness days (1..365). Null uses DB default.
     * @return array{
     *   data: array{
     *     rbac_denies: array{
     *       window_days:int,
     *       from: non-empty-string,
     *       to: non-empty-string,
     *       denies:int,
     *       total:int,
     *       rate:float,
     *       daily: list<array{date: non-empty-string, denies:int, total:int, rate:float}>
     *     },
     *     evidence_freshness: array{
     *       days:int,
     *       total:int,
     *       stale:int,
     *       percent:float,
     *       by_mime: list<array{mime: non-empty-string, total:int, stale:int, percent:float}>
     *     }
     *   },
     *   cache: array{ttl:int, hit:bool}
     * }
     */
    public function snapshotWithMeta(?int $deniesWindowDays = null, ?int $freshnessDays = null): array
    {
        $defaults = $this->loadDefaults(); // [rbac_days:int, fresh_days:int, ttl:int]

        $rbacDays  = $this->clampDays($deniesWindowDays ?? $defaults['rbac_days']);
        $freshDays = $this->clampDays($freshnessDays    ?? $defaults['fresh_days']);
        $ttl       = $this->clampTtlAllowZero($defaults['ttl']); // 0 disables

        $key = sprintf('metrics.snapshot.v1:rbac=%d:fresh=%d', $rbacDays, $freshDays);

        $store = Cache::store('array');

        if ($ttl > 0 && $store->has($key)) {
            /** @var mixed $raw */
            $raw = $store->get($key);
            if (is_array($raw)) {
                /** @var array{
                 *   rbac_denies: array{
                 *     window_days:int,
                 *     from: non-empty-string,
                 *     to: non-empty-string,
                 *     denies:int,
                 *     total:int,
                 *     rate:float,
                 *     daily: list<array{date: non-empty-string, denies:int, total:int, rate:float}>
                 *   },
                 *   evidence_freshness: array{
                 *     days:int,
                 *     total:int,
                 *     stale:int,
                 *     percent:float,
                 *     by_mime: list<array{mime: non-empty-string, total:int, stale:int, percent:float}>
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
         *   denies:int,
         *   total:int,
         *   rate:float,
         *   daily: list<array{date: non-empty-string, denies:int, total:int, rate:float}>
         * } $rbac
         */
        $rbac = $this->rbacDenies->compute($rbacDays);

        /** @var array{
         *   days:int,
         *   total:int,
         *   stale:int,
         *   percent:float,
         *   by_mime: list<array{mime: non-empty-string, total:int, stale:int, percent:float}>
         * } $fresh
         */
        $fresh = $this->evidenceFreshness->compute($freshDays);

        /** @var array{
         *   rbac_denies: array{
         *     window_days:int,
         *     from: non-empty-string,
         *     to: non-empty-string,
         *     denies:int,
         *     total:int,
         *     rate:float,
         *     daily: list<array{date: non-empty-string, denies:int, total:int, rate:float}>
         *   },
         *   evidence_freshness: array{
         *     days:int,
         *     total:int,
         *     stale:int,
         *     percent:float,
         *     by_mime: list<array{mime: non-empty-string, total:int, stale:int, percent:float}>
         *   }
         * } $payload
         */
        $payload = [
            'rbac_denies'        => $rbac,
            'evidence_freshness' => $fresh,
        ];

        if ($ttl > 0) {
            $store->put($key, $payload, $ttl);
        }

        return ['data' => $payload, 'cache' => ['ttl' => $ttl, 'hit' => false]];
    }

    /**
     * Read DB-backed defaults from SettingsService with hard fallbacks.
     *
     * @return array{rbac_days:int, fresh_days:int, ttl:int}
     */
    private function loadDefaults(): array
    {
        // TTL: read directly from config to respect runtime overrides in tests.
        /** @var mixed $cfgTtl */
        $cfgTtl = config('core.metrics.cache_ttl_seconds');
        $ttl = $this->clampTtlAllowZero($cfgTtl); // 0 disables

        // Other defaults from effective config (typed, contract-trimmed).
        /** @var array<string,mixed> $eff */
        $eff = $this->settings->effectiveConfig();

        /** @var array<string,mixed> $metrics */
        $metrics = [];
        if (isset($eff['core']) && is_array($eff['core']) && isset($eff['core']['metrics']) && is_array($eff['core']['metrics'])) {
            $metrics = $eff['core']['metrics'];
        }

        $freshDays = 30;
        if (isset($metrics['evidence_freshness']) && is_array($metrics['evidence_freshness']) && isset($metrics['evidence_freshness']['days'])) {
            $freshDays = $this->clampDays($metrics['evidence_freshness']['days']);
        }

        $rbacDays = 7;
        if (isset($metrics['rbac_denies']) && is_array($metrics['rbac_denies']) && isset($metrics['rbac_denies']['window_days'])) {
            $rbacDays = $this->clampDays($metrics['rbac_denies']['window_days']);
        }

        return [
            'rbac_days'  => $rbacDays,
            'fresh_days' => $freshDays,
            'ttl'        => $ttl,
        ];
    }

    /** @param mixed $n */
    private function clampDays(mixed $n): int
    {
        $v = is_int($n) ? $n : (is_string($n) && ctype_digit($n) ? (int) $n : 0);
        if ($v < 1) { $v = 1; }
        if ($v > 365) { $v = 365; }
        return $v;
    }

    /** @param mixed $n */
    private function clampTtlAllowZero(mixed $n): int
    {
        $v = is_int($n) ? $n : (is_string($n) && ctype_digit($n) ? (int) $n : -1);
        if ($v < 0) { $v = 0; }      // 0 disables
        if ($v > 3600) { $v = 3600; }
        return $v;
    }
}

