<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditRetentionPurge extends Command
{
    protected $signature = 'audit:purge {--days=} {--dry-run}';
    protected $description = 'Purge audit_events older than the configured retention window.';

    public function handle(): int
    {
        $daysOpt = $this->option('days');
        $days    = is_numeric($daysOpt) ? (int) $daysOpt : (int) config('core.audit.retention_days', 365);

        // Clamp to [30, 730] to guard against accidental misconfiguration.
        if ($days < 30 || $days > 730) {
            $this->error('AUDIT_RETENTION_INVALID: days must be between 30 and 730.');
            return self::FAILURE;
        }

        $cutoff = Carbon::now('UTC')->subDays($days);

        $total = AuditEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->count();

        $this->line(sprintf(
            'audit:purge inspecting cutoff=%s days=%d matches=%d %s',
            $cutoff->toIso8601String(),
            $days,
            $total,
            $this->option('dry-run') ? '(dry-run)' : ''
        ));

        if ($total === 0 || (bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Chunked deletes to reduce lock time and memory.
        $deleted = 0;
        do {
            $batchIds = AuditEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id')
                ->all();

            if ($batchIds === []) {
                break;
            }

            DB::transaction(function () use ($batchIds): void {
                AuditEvent::query()->whereIn('id', $batchIds)->delete();
            });

            $deleted += count($batchIds);
            $this->line("Deleted batch of " . count($batchIds) . " (total {$deleted}/{$total})");
        } while (true);

        $this->info('audit:purge completed.');
        return self::SUCCESS;
    }
}

