<?php

declare(strict_types=1);

namespace App\Http\Controllers\Metrics;

use App\Services\Metrics\EvidenceFreshnessCalculator;
use App\Services\Metrics\RbacDeniesCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class MetricsController extends Controller
{
    public function index(
        Request $request,
        RbacDeniesCalculator $rbacDenies,
        EvidenceFreshnessCalculator $evidenceFreshness,
    ): JsonResponse {
        /** @var mixed $freshCfg */
        $freshCfg = config('core.metrics.evidence_freshness.days');
        /** @var mixed $rbacCfg */
        $rbacCfg = config('core.metrics.rbac_denies.window_days');

        $freshDefault = $this->intFromConfig($freshCfg, 30);
        $rbacDefault  = $this->intFromConfig($rbacCfg, 7);

        $freshDays = $this->clampQueryInt($request, 'days', $freshDefault, 1, 365);
        $rbacDays  = $this->clampQueryInt($request, 'rbac_days', $rbacDefault, 1, 365);

        /** @var array{
         *   window_days:int, from:non-empty-string, to:non-empty-string, denies:int, total:int, rate:float,
         *   daily:list<array{date:non-empty-string, denies:int, total:int, rate:float}>
         * } $rbac
         */
        $rbac = $rbacDenies->compute($rbacDays);

        /** @var array{
         *   days:int,total:int,stale:int,percent:float,
         *   by_mime:list<array{mime:non-empty-string,total:int,stale:int,percent:float}>
         * } $fresh
         */
        $fresh = $evidenceFreshness->compute($freshDays);

        return response()->json([
            'ok'   => true,
            'data' => [
                'rbac_denies'        => $rbac,
                'evidence_freshness' => $fresh,
            ],
            'meta' => [
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'window'       => ['rbac_days' => $rbacDays, 'fresh_days' => $freshDays],
            ],
        ], 200);
    }

    /** Safe int parse for mixed config values with clamp. */
    private function intFromConfig(mixed $val, int $fallback): int
    {
        $n = $fallback;
        if (is_int($val)) {
            $n = $val;
        } elseif (is_string($val) && ctype_digit($val)) {
            $n = (int) $val;
        }
        return max(1, min(365, $n));
    }

    /** Accepts string or array query params. Falls back to default, then clamps. */
    private function clampQueryInt(Request $req, string $key, int $default, int $min, int $max): int
    {
        /** @var mixed $raw */
        $raw = $req->query($key);

        $val = null;
        if (is_array($raw)) {
            /** @var mixed $first */
            $first = $raw[0] ?? null;
            $val = is_string($first) ? trim($first) : null;
        } elseif (is_string($raw)) {
            $val = trim($raw);
        }

        $n = $default;
        if (is_string($val) && preg_match('/^-?\d+$/', $val) === 1) {
            $n = (int) $val;
        }

        if ($n < $min) {
            $n = $min;
        } elseif ($n > $max) {
            $n = $max;
        }

        return $n;
    }
}

