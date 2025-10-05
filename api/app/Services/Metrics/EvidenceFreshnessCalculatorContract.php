<?php
declare(strict_types=1);

namespace App\Services\Metrics;

interface EvidenceFreshnessCalculatorContract
{
    /**
     * @return array{
     *   days:int,
     *   total:int,
     *   stale:int,
     *   percent:float,
     *   by_mime:list<array{mime:non-empty-string,total:int,stale:int,percent:float}>
     * }
     */
    public function compute(int $days = 30): array;
}

