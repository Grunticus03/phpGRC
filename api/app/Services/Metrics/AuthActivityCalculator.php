<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;

/**
 * Aggregate authentication activity (success vs failed logins) over a rolling window.
 *
 * @psalm-type DailyShape=array{
 *   date: non-empty-string,
 *   success:int,
 *   failed:int,
 *   total:int
 * }
 * @psalm-type OutputShape=array{
 *   window_days:int,
 *   from: non-empty-string,
 *   to: non-empty-string,
 *   daily:list<DailyShape>,
 *   totals:array{success:int,failed:int,total:int},
 *   max_daily_total:int
 * }
 */
final class AuthActivityCalculator implements MetricsCalculator
{
    private const MIN_WINDOW = 1;

    private const MAX_WINDOW = 365;

    private const SUCCESS_ACTION = 'auth.login';

    private const FAILED_ACTION = 'auth.login.failed';

    #[\Override]
    /**
     * @return OutputShape
     */
    public function compute(int $windowDays): array
    {
        $days = $this->clampWindow($windowDays);

        $end = CarbonImmutable::now('UTC')->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();

        /** @var array<string,array{success:int,failed:int}> $buckets */
        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $label = $start->addDays($i)->format('Y-m-d');
            $buckets[$label] = ['success' => 0, 'failed' => 0];
        }

        /** @var iterable<AuditEvent> $events */
        $events = AuditEvent::query()
            ->where('category', '=', 'AUTH')
            ->whereIn('action', [self::SUCCESS_ACTION, self::FAILED_ACTION])
            ->whereBetween('occurred_at', [$start, $end])
            ->get(['occurred_at', 'action']);

        $totalSuccess = 0;
        $totalFailed = 0;

        foreach ($events as $event) {
            /** @var mixed $occurredAt */
            $occurredAt = $event->getAttribute('occurred_at');
            if ($occurredAt instanceof CarbonImmutable) {
                $ts = $occurredAt->setTimezone('UTC');
            } elseif ($occurredAt instanceof \DateTimeInterface) {
                $ts = CarbonImmutable::instance($occurredAt)->setTimezone('UTC');
            } elseif (is_string($occurredAt) && $occurredAt !== '') {
                try {
                    $ts = CarbonImmutable::parse($occurredAt, 'UTC')->setTimezone('UTC');
                } catch (\Throwable) {
                    continue;
                }
            } else {
                continue;
            }

            $label = $ts->format('Y-m-d');

            if (! array_key_exists($label, $buckets)) {
                continue;
            }

            /** @var mixed $actionAttr */
            $actionAttr = $event->getAttribute('action');
            if (! is_string($actionAttr) || $actionAttr === '') {
                continue;
            }
            $action = $actionAttr;
            if ($action === self::SUCCESS_ACTION) {
                $buckets[$label]['success']++;
                $totalSuccess++;
            } elseif ($action === self::FAILED_ACTION) {
                $buckets[$label]['failed']++;
                $totalFailed++;
            }
        }

        $daily = [];
        $maxDaily = 0;

        foreach ($buckets as $date => $counts) {
            $success = max(0, $counts['success']);
            $failed = max(0, $counts['failed']);
            $total = $success + $failed;
            if ($total > $maxDaily) {
                $maxDaily = $total;
            }

            /** @var non-empty-string $date */
            $daily[] = [
                'date' => $date,
                'success' => $success,
                'failed' => $failed,
                'total' => $total,
            ];
        }

        /** @var non-empty-string $fromIso */
        $fromIso = $start->toIso8601String();
        /** @var non-empty-string $toIso */
        $toIso = $end->toIso8601String();

        return [
            'window_days' => $days,
            'from' => $fromIso,
            'to' => $toIso,
            'daily' => $daily,
            'totals' => [
                'success' => $totalSuccess,
                'failed' => $totalFailed,
                'total' => $totalSuccess + $totalFailed,
            ],
            'max_daily_total' => $maxDaily,
        ];
    }

    private function clampWindow(int $days): int
    {
        if ($days < self::MIN_WINDOW) {
            return self::MIN_WINDOW;
        }
        if ($days > self::MAX_WINDOW) {
            return self::MAX_WINDOW;
        }

        return $days;
    }
}
