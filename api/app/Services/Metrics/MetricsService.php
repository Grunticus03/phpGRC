<?php
declare(strict_types=1);

namespace App\Services\Metrics;

use App\Services\Metrics\RbacDeniesCalculator;
use App\Services\Metrics\EvidenceFreshnessCalculatorContract;

final class MetricsService
{
    public function __construct(
        private readonly RbacDeniesCalculator $rbacDenies,
        private readonly EvidenceFreshnessCalculatorContract $evidenceFreshness
    ) {
    }

    /**
     * @return array{
     *   rbac_denies: array{
     *     window_days:int,
     *     from:non-empty-string,
     *     to:non-empty-string,
     *     denies:int,
     *     total:int,
     *     rate:float,
     *     daily:list<array{date:non-empty-string,denies:int,total:int,rate:float}>
     *   },
     *   evidence_freshness: array{
     *     days:int,
     *     total:int,
     *     stale:int,
     *     percent:float,
     *     by_mime:list<array{mime:non-empty-string,total:int,stale:int,percent:float}>
     *   }
     * }
     */
    public function snapshot(): array
    {
        $rbac  = $this->rbacDenies->compute(7);
        $fresh = $this->evidenceFreshness->compute(30);

        return [
            'rbac_denies'        => $rbac,
            'evidence_freshness' => $fresh,
        ];
    }
}

