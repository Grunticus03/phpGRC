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

        /** @var array<string>|string|null $freshParam */
        $freshParam = $request->query('days');
        /** @var array<string>|string|null $rbacParam */
        $rbacParam  = $request->query('rbac_days');

        $freshDays = $freshDefault;
        if (is_string($freshParam) && ctype_digit($freshParam)) {
            $freshDays = max(1, min(365, (int) $freshParam));
        }

        $rbacDays = $rbacDefault;
        if (is_string($rbacParam) && ctype_digit($rbacParam)) {
            $rbacDays = max(1, min(365, (int) $rbacParam));
        }

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

    /**
     * Safe int parse for mixed config values.
     */
    private function intFromConfig(mixed $val, int $fallback): int
    {
        if (is_int($val)) {
            return $val;
        }
        if (is_string($val) && ctype_digit($val)) {
            return (int) $val;
        }
        return $fallback;
    }
}

