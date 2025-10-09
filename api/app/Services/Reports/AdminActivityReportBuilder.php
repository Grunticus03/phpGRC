<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\AuditEvent;
use App\Services\Metrics\AdminActivityCalculator;
use Carbon\CarbonImmutable;

/**
 * Build the Admin Activity report by enriching the admin snapshot with login statistics.
 *
 * @psalm-type AdminSnapshot=array{
 *   admins:list<array{
 *     id:int|string,
 *     name:string,
 *     email:string,
 *     last_login_at:string|null
 *   }>
 * }
 * @psalm-type ReportRow=array{
 *   id:int,
 *   name:string,
 *   email:string,
 *   last_login_at:string|null,
 *   logins_total:int,
 *   logins_30_days:int,
 *   logins_7_days:int
 * }
 * @psalm-type ReportPayload=array{
 *   generated_at:non-empty-string,
 *   rows:list<ReportRow>,
 *   totals:array{
 *     admins:int,
 *     logins_total:int,
 *     logins_30_days:int,
 *     logins_7_days:int
 *   }
 * }
 */
final class AdminActivityReportBuilder
{
    public function __construct(
        private readonly AdminActivityCalculator $calculator
    ) {}

    /**
     * Generate the report payload ready for serialization.
     *
     * @return ReportPayload
     */
    public function build(): array
    {
        /** @var AdminSnapshot $snapshot */
        $snapshot = $this->calculator->compute();

        $now = CarbonImmutable::now('UTC');
        $generatedAt = $now->toIso8601String();

        if ($generatedAt === '') {
            throw new \LogicException('Failed to generate admin activity timestamp.');
        }

        /** @var list<ReportRow> $rows */
        $rows = [];
        /** @var array{admins:int,logins_total:int,logins_30_days:int,logins_7_days:int} $totals */
        $totals = [
            'admins' => 0,
            'logins_total' => 0,
            'logins_30_days' => 0,
            'logins_7_days' => 0,
        ];

        if ($snapshot['admins'] === []) {
            return [
                'generated_at' => $generatedAt,
                'rows' => $rows,
                'totals' => $totals,
            ];
        }

        $stats = $this->fetchLoginStats($snapshot['admins'], $now);

        foreach ($snapshot['admins'] as $admin) {
            $rawId = $admin['id'];
            $id = is_int($rawId) ? $rawId : (int) $rawId;
            $stat = $stats[$id] ?? ['total' => 0, 'last30' => 0, 'last7' => 0];

            $row = [
                'id' => $id,
                'name' => $admin['name'],
                'email' => $admin['email'],
                'last_login_at' => $admin['last_login_at'],
                'logins_total' => $stat['total'],
                'logins_30_days' => $stat['last30'],
                'logins_7_days' => $stat['last7'],
            ];

            $rows[] = $row;

            $totals['admins']++;
            $totals['logins_total'] += $row['logins_total'];
            $totals['logins_30_days'] += $row['logins_30_days'];
            $totals['logins_7_days'] += $row['logins_7_days'];
        }

        return [
            'generated_at' => $generatedAt,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * @param  list<array{id:int|string,name:string,email:string,last_login_at:string|null}>  $admins
     * @return array<int,array{total:int,last30:int,last7:int}>
     */
    private function fetchLoginStats(array $admins, CarbonImmutable $now): array
    {
        $userIds = [];

        foreach ($admins as $row) {
            $rawId = $row['id'];
            $id = null;

            if (is_int($rawId)) {
                $id = $rawId;
            } elseif ($rawId !== '' && is_numeric($rawId)) {
                $id = (int) $rawId;
            }

            if ($id !== null && $id > 0) {
                $userIds[] = $id;
            }
        }

        if ($userIds === []) {
            return [];
        }

        $cutoff30 = $now->subDays(30);
        $cutoff7 = $now->subDays(7);

        /** @var array<int,array{total:int,last30:int,last7:int}> $stats */
        $stats = [];

        AuditEvent::query()
            ->select(['actor_id', 'occurred_at'])
            ->where('category', 'AUTH')
            ->where('audit_events.action', 'auth.login')
            ->whereIn('actor_id', $userIds)
            ->each(static function (AuditEvent $event) use (&$stats, $cutoff30, $cutoff7): void {
                /** @var mixed $actorRaw */
                $actorRaw = $event->getAttribute('actor_id');
                $actorId = is_int($actorRaw) ? $actorRaw : (is_numeric($actorRaw) ? (int) $actorRaw : null);
                if ($actorId === null || $actorId <= 0) {
                    return;
                }

                if (! isset($stats[$actorId])) {
                    $stats[$actorId] = ['total' => 0, 'last30' => 0, 'last7' => 0];
                }

                $stats[$actorId]['total']++;

                /** @var mixed $occurred */
                $occurred = $event->getAttribute('occurred_at');
                if ($occurred instanceof CarbonImmutable) {
                    $ts = $occurred;
                } elseif (is_string($occurred) && $occurred !== '') {
                    try {
                        $ts = CarbonImmutable::parse($occurred, 'UTC');
                    } catch (\Throwable) {
                        $ts = null;
                    }
                } else {
                    $ts = null;
                }

                if ($ts === null) {
                    return;
                }

                if ($ts->greaterThanOrEqualTo($cutoff30)) {
                    $stats[$actorId]['last30']++;
                }
                if ($ts->greaterThanOrEqualTo($cutoff7)) {
                    $stats[$actorId]['last7']++;
                }
            });

        return $stats;
    }
}
