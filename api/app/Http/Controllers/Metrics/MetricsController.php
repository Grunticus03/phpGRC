<?php

declare(strict_types=1);

namespace App\Http\Controllers\Metrics;

use App\Http\Controllers\Controller;
use App\Services\Metrics\CachedMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class MetricsController extends Controller
{
    /**
     * Back-compat for routes that still point to MetricsController@index.
     */
    public function index(Request $request, CachedMetricsService $metrics): JsonResponse
    {
        return $this->kpis($request, $metrics);
    }

    public function kpis(Request $request, CachedMetricsService $metrics): JsonResponse
    {
        $defaultFresh = $this->cfgInt('core.metrics.evidence_freshness.days', 30);
        $defaultRbac  = $this->cfgInt('core.metrics.rbac_denies.window_days', 7);

        $freshDays = $this->parseWindow($request->query('days'), $defaultFresh);
        $rbacDays  = $this->parseWindow($request->query('rbac_days'), $defaultRbac);

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
         * } $res
         */
        $res = $metrics->snapshotWithMeta($rbacDays, $freshDays);

        $data  = $res['data'];
        $cache = $res['cache']; // array{ttl:int, hit:bool}

        return new JsonResponse([
            'ok'   => true,
            'data' => $data,
            'meta' => [
                'generated_at' => now('UTC')->toIso8601String(),
                'window'       => ['rbac_days' => $rbacDays, 'fresh_days' => $freshDays],
                'cache'        => ['ttl' => $cache['ttl'], 'hit' => $cache['hit']],
            ],
        ], 200);
    }

    private function cfgInt(string $key, int $fallback): int
    {
        /** @var mixed $v */
        $v = config($key);

        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '' && ctype_digit($v)) {
            return (int) $v;
        }
        return $fallback;
    }

    /**
     * @param mixed $raw query param (int|string|array<int|string>|null)
     */
    private function parseWindow(mixed $raw, int $fallback): int
    {
        /** @var mixed $value */
        $value = is_array($raw) ? Arr::first($raw) : $raw;

        if (is_int($value)) {
            $n = $value;
        } elseif (is_string($value) && ctype_digit(ltrim($value, '+-'))) {
            $n = (int) $value;
        } else {
            $n = $fallback;
        }

        if ($n < 1) {
            return 1;
        }
        if ($n > 365) {
            return 365;
        }
        return $n;
    }
}

