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
        /** @var array<string>|string|null $daysParam */
        $daysParam = $request->query('days');

        $days = 30;
        if (is_string($daysParam) && ctype_digit($daysParam)) {
            $days = max(1, min(365, (int) $daysParam));
        }

        /** @var array{
         *   window_days:int, from:non-empty-string, to:non-empty-string, denies:int, total:int, rate:float,
         *   daily:list<array{date:non-empty-string, denies:int, total:int, rate:float}>
         * } $rbac
         */
        $rbac = $rbacDenies->compute(7);

        /** @var array{
         *   days:int,total:int,stale:int,percent:float,
         *   by_mime:list<array{mime:non-empty-string,total:int,stale:int,percent:float}>
         * } $fresh
         */
        $fresh = $evidenceFreshness->compute($days);

        return response()->json([
            'ok'   => true,
            'data' => [
                'rbac_denies'        => $rbac,
                'evidence_freshness' => $fresh,
            ],
            'meta' => [
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'window'       => ['rbac_days' => 7, 'fresh_days' => $days],
            ],
        ], 200);
    }
}

