<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class AuditRetentionPurge extends Command
{
    /**
     * Purge audit_events older than N days.
     */
    protected $signature = 'audit:purge {--days=} {--dry-run} {--emit-summary}';

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

        $daysOptRaw = $this->option('days');
        /** @var mixed $daysCfgRaw */
        $daysCfgRaw = Config::get('core.audit.retention_days', 365);

        $daysCfg = (is_int($daysCfgRaw) || (is_string($daysCfgRaw) && ctype_digit($daysCfgRaw)))
            ? (int) $daysCfgRaw
            : 365;

        // CLI option is provided as string|null by Symfony Console.
        $daysOpt = is_string($daysOptRaw) && ctype_digit($daysOptRaw) ? (int) $daysOptRaw : null;

        $days = $daysOpt !== null ? $daysOpt : $daysCfg;

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
        $totalCandidates = AuditEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->count();

        $dryRun = $this->option('dry-run') === true;

        if ($dryRun) {
            $this->line($this->json([
                'ok' => true,
                'dry_run' => true,
                'days' => $days,
                'cutoff_utc' => $cutoff->toIso8601String(),
                'candidates' => $totalCandidates,
            ]));
            return self::SUCCESS;
        }

        /** @var int $deleted */
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
                /** @var int $count */
                $count = AuditEvent::query()
                    ->whereIn('id', $ids)
                    ->delete();
                $deleted += $count;
            }, 1);
        }

        // Optional summary event (opt-in only).
        $emitSummary = $this->option('emit-summary') === true;

        if ($emitSummary) {
            try {
                $now = CarbonImmutable::now('UTC');

                AuditEvent::query()->create([
                    'id'           => Str::ulid()->toBase32(), // string
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
            } catch (\Throwable) {
                // Swallow per spec.
            }
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
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '{}';
    }
}

