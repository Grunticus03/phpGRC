<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;

/**
 * RBAC denies rate over the last N calendar days with daily buckets.
 *
 * Denominator = (AUTH events) + (unique RBAC deny events) in window.
 * Numerator   = unique RBAC deny events (deduped by meta.request_id if present, else by id).
 *
 * @psalm-type DailyShape=array{date: non-empty-string, denies:int, total:int, rate:float}
 * @psalm-type OutputShape=array{
 *   window_days:int,
 *   from: non-empty-string,
 *   to: non-empty-string,
 *   denies:int,
 *   total:int,
 *   rate:float,
 *   daily:list<DailyShape>
 * }
 */
final class RbacDeniesCalculator implements MetricsCalculator
{
    #[\Override]
    /**
     * @return OutputShape
     */
    public function compute(int $windowDays = 7): array
    {
        $windowDays = max(1, $windowDays);

        $now  = CarbonImmutable::now('UTC');
        $to   = $now->endOfDay();
        $from = $to->subDays($windowDays - 1)->startOfDay();

        /** @var list<non-empty-string> $denyActions */
        $denyActions = [
            'rbac.deny.unauthenticated',
            'rbac.deny.capability',
            'rbac.deny.role_mismatch',
            'rbac.deny.policy',
            'rbac.deny.unknown_policy',
        ];

        // Initialize per-day buckets
        /** @var array<string,array{denies:int,total:int}> $dayMap */
        $dayMap = [];
        for ($i = 0; $i < $windowDays; $i++) {
            $d = $from->addDays($i)->format('Y-m-d');
            assert($d !== '');
            $dayMap[$d] = ['denies' => 0, 'total' => 0];
        }

        // 1) AUTH events (always counted in denominator)
        /** @var iterable<AuditEvent> $authEvents */
        $authEvents = AuditEvent::query()
            ->where('category', 'AUTH')
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['occurred_at']);

        $authTotal = 0;
        foreach ($authEvents as $e) {
            $d = CarbonImmutable::parse((string) $e->occurred_at)->setTimezone('UTC')->format('Y-m-d');
            assert($d !== '');
            if (isset($dayMap[$d])) {
                $dayMap[$d]['total']++;
                $authTotal++;
            }
        }

        // 2) RBAC deny events (counted in numerator and denominator, de-duplicated)
        /** @var iterable<AuditEvent> $rbacDenies */
        $rbacDenies = AuditEvent::query()
            ->where('category', 'RBAC')
            ->whereIn('action', $denyActions)
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['id', 'occurred_at', 'meta']);

        /** @var array<string,string> $uniqueDenies key=request_id|id => ISO date-time */
        $uniqueDenies = [];
        foreach ($rbacDenies as $e) {
            $key = $e->id;
            $meta = $e->meta;
            if (\is_array($meta) && isset($meta['request_id']) && \is_string($meta['request_id']) && $meta['request_id'] !== '') {
                $key = $meta['request_id'];
            }
            $iso = CarbonImmutable::parse((string) $e->occurred_at)->toIso8601String();
            assert($iso !== '');
            /** @psalm-var non-empty-string $iso */
            $uniqueDenies[$key] = $iso;
        }

        // Tally denies into buckets and denominator
        foreach ($uniqueDenies as $iso) {
            $d = CarbonImmutable::parse($iso)->setTimezone('UTC')->format('Y-m-d');
            assert($d !== '');
            if (isset($dayMap[$d])) {
                $dayMap[$d]['denies']++;
                $dayMap[$d]['total']++; // denominator includes unique denies
            }
        }

        $denies = \count($uniqueDenies);
        $total  = $authTotal + $denies;
        $rate   = $total > 0 ? $denies / $total : 0.0;

        // Build daily series
        /** @var list<array{date: non-empty-string, denies:int, total:int, rate:float}> $daily */
        $daily = [];
        foreach ($dayMap as $date => $vals) {
            assert($date !== '');
            /** @psalm-var non-empty-string $date */
            $drate = $vals['total'] > 0 ? $vals['denies'] / $vals['total'] : 0.0;
            $daily[] = [
                'date'   => $date,
                'denies' => $vals['denies'],
                'total'  => $vals['total'],
                'rate'   => (float) $drate,
            ];
        }

        $fromIso = $from->toIso8601String();
        $toIso   = $to->toIso8601String();
        assert($fromIso !== '' && $toIso !== '');
        /** @psalm-var non-empty-string $fromIso */
        /** @psalm-var non-empty-string $toIso */

        return [
            'window_days' => $windowDays,
            'from'        => $fromIso,
            'to'          => $toIso,
            'denies'      => $denies,
            'total'       => $total,
            'rate'        => $rate,
            'daily'       => $daily,
        ];
    }
}
