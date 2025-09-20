<?php
declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;

/**
 * Computes RBAC deny rate and daily buckets without DB-specific DATE_TRUNC.
 */
final class RbacDeniesCalculator
{
    /**
     * @return array{
     *   window_days:int,
     *   from:non-empty-string,
     *   to:non-empty-string,
     *   denies:int,
     *   total:int,
     *   rate:float,
     *   daily:list<array{date:non-empty-string,denies:int,total:int,rate:float}>
     * }
     */
    public function compute(int $windowDays = 7): array
    {
        $to   = CarbonImmutable::now('UTC')->endOfDay();
        $from = $to->subDays(max(0, $windowDays - 1))->startOfDay();

        /** @var list<AuditEvent> $events */
        $events = AuditEvent::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->whereIn('category', ['RBAC', 'AUTH'])
            ->get()
            ->all();

        $total  = 0;
        $denies = 0;

        /** @var array<string,array{denies:int,total:int}> $days */
        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $days[$d->toDateString()] = ['denies' => 0, 'total' => 0];
        }

        foreach ($events as $e) {
            $key = CarbonImmutable::parse($e->occurred_at)->utc()->toDateString();
            if (!isset($days[$key])) {
                $days[$key] = ['denies' => 0, 'total' => 0];
            }
            $total++;
            $days[$key]['total']++;

            if ($e->category === 'RBAC' && str_starts_with($e->action, 'rbac.deny.')) {
                $denies++;
                $days[$key]['denies']++;
            }
        }

        /** @var list<array{date:non-empty-string,denies:int,total:int,rate:float}> $daily */
        $daily = [];
        foreach ($days as $dateKey => $agg) {
            /** @var string $date */
            $date = $dateKey; // keys are from toDateString()
            /** @phpstan-assert non-empty-string $date */
            assert($date !== '');

            $rate = $agg['total'] > 0 ? (float) ($agg['denies'] / $agg['total']) : 0.0;
            $daily[] = [
                'date'   => $date,
                'denies' => $agg['denies'],
                'total'  => $agg['total'],
                'rate'   => $rate,
            ];
        }

        $fromIso = $from->toIso8601String();
        /** @phpstan-assert non-empty-string $fromIso */
        assert($fromIso !== '');

        $toIso = $to->toIso8601String();
        /** @phpstan-assert non-empty-string $toIso */
        assert($toIso !== '');

        return [
            'window_days' => $windowDays,
            'from'        => $fromIso,
            'to'          => $toIso,
            'denies'      => $denies,
            'total'       => $total,
            'rate'        => $total > 0 ? (float) ($denies / $total) : 0.0,
            'daily'       => $daily,
        ];
    }
}

