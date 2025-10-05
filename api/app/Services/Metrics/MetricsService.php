<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Services\Metrics\EvidenceFreshnessCalculator;
use App\Services\Metrics\RbacDeniesCalculator;

final class MetricsService
{
    private RbacDeniesCalculator $rbacDenies;
    private EvidenceFreshnessCalculator $evidenceFreshness;

    public function __construct(
        RbacDeniesCalculator $rbacDenies,
        EvidenceFreshnessCalculator $evidenceFreshness
    ) {
        $this->rbacDenies = $rbacDenies;
        $this->evidenceFreshness = $evidenceFreshness;
    }

    /**
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
    public function snapshot(int $deniesWindowDays = 7, int $freshnessDays = 30): array
    {
        $rbacDays  = self::clampDays($deniesWindowDays);
        $freshDays = self::clampDays($freshnessDays);

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

        return [
            'rbac_denies'        => $rbac,
            'evidence_freshness' => $fresh,
        ];
    }

    private static function clampDays(int $n): int
    {
        if ($n < 1) return 1;
        if ($n > 365) return 365;
        return $n;
    }
}

