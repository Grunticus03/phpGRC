<?php

declare(strict_types=1);

namespace App\Http\Controllers\Metrics;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class MetricsController extends Controller
{
    /**
     * Back-compat for routes that still point to MetricsController@index.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->kpis($request);
    }

    public function kpis(Request $request): JsonResponse
    {
        $defaultFresh = $this->cfgInt('core.metrics.evidence_freshness.days', 30);
        $defaultRbac  = $this->cfgInt('core.metrics.rbac_denies.window_days', 7);

        $freshDays = $this->parseWindow($request->query('days'), $defaultFresh);
        $rbacDays  = $this->parseWindow($request->query('rbac_days'), $defaultRbac);

        $to   = CarbonImmutable::now('UTC')->startOfDay();
        $from = $to->subDays($rbacDays - 1);

        // RBAC/AUTH totals
        $rbacTotal = DB::table('audit_events')
            ->whereIn('category', ['RBAC', 'AUTH'])
            ->whereBetween('occurred_at', [$from, $to->endOfDay()])
            ->count();

        $rbacDenies = DB::table('audit_events')
            ->where('category', 'RBAC')
            ->where('action', 'like', 'rbac.deny.%')
            ->whereBetween('occurred_at', [$from, $to->endOfDay()])
            ->count();

        $rate = $rbacTotal > 0 ? ($rbacDenies / $rbacTotal) : 0.0;

        $daily = DB::table('audit_events')
            ->selectRaw("DATE(occurred_at) as d")
            ->selectRaw("SUM(CASE WHEN action LIKE 'rbac.deny.%' THEN 1 ELSE 0 END) as denies")
            ->selectRaw("COUNT(*) as total")
            ->whereIn('category', ['RBAC', 'AUTH'])
            ->whereBetween('occurred_at', [$from, $to->endOfDay()])
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(static function ($row): array {
                $denies = is_numeric($row->denies ?? null) ? (int) $row->denies : 0;
                $total  = is_numeric($row->total ?? null)  ? (int) $row->total  : 0;
                $r      = $total > 0 ? $denies / $total : 0.0;
                return [
                    'date'   => (string) ($row->d ?? ''),
                    'denies' => $denies,
                    'total'  => $total,
                    'rate'   => $r,
                ];
            })
            ->all();

        // Evidence freshness
        $cutoff = CarbonImmutable::now('UTC')->subDays($freshDays);

        $evTotal = DB::table('evidence')->count();
        $evStale = DB::table('evidence')->where('updated_at', '<', $cutoff)->count();
        $evPct   = $evTotal > 0 ? ($evStale / $evTotal) : 0.0;

        $byMime = DB::table('evidence')
            ->select('mime')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN updated_at < ? THEN 1 ELSE 0 END) as stale", [$cutoff])
            ->groupBy('mime')
            ->orderByDesc('total')
            ->get()
            ->map(static function ($row): array {
                $total = is_numeric($row->total ?? null) ? (int) $row->total : 0;
                $stale = is_numeric($row->stale ?? null) ? (int) $row->stale : 0;
                return [
                    'mime'    => (string) ($row->mime ?? ''),
                    'total'   => $total,
                    'stale'   => $stale,
                    'percent' => $total > 0 ? $stale / $total : 0.0,
                ];
            })
            ->all();

        return new JsonResponse([
            'ok'   => true,
            'data' => [
                'rbac_denies' => [
                    'window_days' => $rbacDays,
                    'from'        => $from->toDateString(),
                    'to'          => $to->toDateString(),
                    'denies'      => $rbacDenies,
                    'total'       => $rbacTotal,
                    'rate'        => $rate,
                    'daily'       => $daily,
                ],
                'evidence_freshness' => [
                    'days'    => $freshDays,
                    'total'   => $evTotal,
                    'stale'   => $evStale,
                    'percent' => $evPct,
                    'by_mime' => $byMime,
                ],
            ],
            'meta' => [
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'window'       => ['rbac_days' => $rbacDays, 'fresh_days' => $freshDays],
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

