<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\Evidence;
use Carbon\CarbonImmutable;

/**
 * Evidence freshness KPI:
 * - percent of evidence items with updated_at older than N days
 * - breakdown by MIME type
 */
final class EvidenceFreshnessCalculator implements EvidenceFreshnessCalculatorContract
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
    #[\Override]
    public function compute(int $days = 30): array
    {
        $days = max(0, $days);
        $now = CarbonImmutable::now('UTC')->endOfDay();
        $cutoff = $now->subDays($days);

        $total = Evidence::query()->count();
        $stale = Evidence::query()
            ->where('updated_at', '<', $cutoff)
            ->count();

        /** @var array<string,int> $totals */
        $totals = Evidence::query()
            ->selectRaw('mime, COUNT(*) as total')
            ->groupBy('mime')
            ->pluck('total', 'mime')
            ->all();

        /** @var array<string,int> $stales */
        $stales = Evidence::query()
            ->where('updated_at', '<', $cutoff)
            ->selectRaw('mime, COUNT(*) as stale')
            ->groupBy('mime')
            ->pluck('stale', 'mime')
            ->all();

        /** @var list<array{mime:non-empty-string,total:int,stale:int,percent:float}> $byMime */
        $byMime = [];
        $allMimes = array_keys($totals + $stales);
        sort($allMimes);
        foreach ($allMimes as $mime) {
            /** @var non-empty-string $m */
            $m = $mime !== '' ? $mime : 'application/octet-stream';
            $t = $totals[$mime] ?? 0;
            $s = $stales[$mime] ?? 0;
            $p = $t > 0 ? (float) ($s / $t) : 0.0;

            $byMime[] = [
                'mime' => $m,
                'total' => $t,
                'stale' => $s,
                'percent' => $p,
            ];
        }

        return [
            'days' => $days,
            'total' => $total,
            'stale' => $stale,
            'percent' => $total > 0 ? (float) ($stale / $total) : 0.0,
            'by_mime' => $byMime,
        ];
    }
}
