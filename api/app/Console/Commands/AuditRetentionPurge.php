<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditRetentionPurge extends Command
{
    /**
     * Purge audit_events older than N days.
     */
    protected $signature = 'audit:purge {--days=} {--dry-run}';

    protected $description = 'Delete audit events older than the configured retention window.';

    private const MIN_DAYS = 30;
    private const MAX_DAYS = 730;
    private const CHUNK    = 1000;

    public function handle(): int
    {
        if (!Config::get('core.audit.enabled', true)) {
            $this->line($this->json([
                'ok' => true,
                'note' => 'audit disabled via config',
            ]));
            return self::SUCCESS;
        }

        $daysOpt = $this->option('days');
        $daysCfg = (int) (Config::get('core.audit.retention_days', 365));
        $days    = $daysOpt !== null && $daysOpt !== ''
            ? (int) $daysOpt
            : $daysCfg;

        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            $this->error($this->json([
                'ok' => false,
                'code' => 'AUDIT_RETENTION_INVALID',
                'days' => $days,
                'allowed_range' => [self::MIN_DAYS, self::MAX_DAYS],
            ]));
            return self::INVALID;
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays($days);

        // Count candidates first.
        $totalCandidates = (int) AuditEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->count();

        if ($this->option('dry-run')) {
            $this->line($this->json([
                'ok' => true,
                'dry_run' => true,
                'days' => $days,
                'cutoff_utc' => $cutoff->toIso8601String(),
                'candidates' => $totalCandidates,
            ]));
            return self::SUCCESS;
        }

        $deleted = 0;

        // Chunked hard-deletes by ID to avoid long-running transactions.
        while (true) {
            /** @var array<int, string> $ids */
            $ids = AuditEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('occurred_at')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            DB::transaction(function () use ($ids, &$deleted): void {
                $deleted += AuditEvent::query()
                    ->whereIn('id', $ids)
                    ->delete();
            }, 1);
        }

        // Best-effort summary event (do not fail command if this write fails).
        try {
            $now = CarbonImmutable::now('UTC');

            AuditEvent::query()->create([
                'id'           => (string) Str::ulid(),
                'occurred_at'  => $now,
                'actor_id'     => null,
                'action'       => 'audit.retention.purged',
                'category'     => 'AUDIT',
                'entity_type'  => 'audit',
                'entity_id'    => 'retention',
                'ip'           => null,
                'ua'           => null,
                'meta'         => [
                    'deleted'     => $deleted,
                    'candidates'  => $totalCandidates,
                    'cutoff_utc'  => $cutoff->toIso8601String(),
                    'days'        => $days,
                    'dry_run'     => false,
                ],
                'created_at'   => $now,
            ]);
        } catch (\Throwable $e) {
            // Swallow silently per spec. We still report success of purge.
        }

        $this->line($this->json([
            'ok' => true,
            'dry_run' => false,
            'days' => $days,
            'cutoff_utc' => $cutoff->toIso8601String(),
            'deleted' => $deleted,
        ]));

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

