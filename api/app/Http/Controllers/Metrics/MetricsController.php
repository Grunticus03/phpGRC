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
        /** @var array<string>|string|int|null $freshParam */
        $freshParam = $request->query('days');
        /** @var array<string>|string|int|null $rbacParam */
        $rbacParam = $request->query('rbac_days');

        $freshDays = 30;
        if (is_int($freshParam)) {
            $freshDays = max(1, min(365, $freshParam));
        } elseif (is_string($freshParam) && ctype_digit($freshParam)) {
            $freshDays = max(1, min(365, (int) $freshParam));
        }

        $rbacDays = 7;
        if (is_int($rbacParam)) {
            $rbacDays = max(1, min(365, $rbacParam));
        } elseif (is_string($rbacParam) && ctype_digit($rbacParam)) {
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
}
