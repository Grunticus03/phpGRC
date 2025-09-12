<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditRetentionPurge extends Command
{
    /**
     * @var string
     */
    protected $signature = 'audit:purge {--days=} {--dry-run}';

    /**
     * @var string
     */
    protected $description = 'Purge audit_events older than the configured retention window.';

    public function handle(): int
    {
        $daysOpt = $this->option('days');
        $days    = is_numeric($daysOpt) ? (int) $daysOpt : (int) config('core.audit.retention_days', 365);

        // Clamp to [1, 730] to guard against accidental misconfiguration.
        if ($days < 1 || $days > 730) {
            $this->error('AUDIT_RETENTION_INVALID: days must be between 1 and 730.');
            return self::FAILURE;
        }

        $cutoff = Carbon::now('UTC')->subDays($days);

        $count = AuditEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->count();

        $this->line(sprintf(
            'audit:purge inspecting cutoff=%s days=%d matches=%d %s',
            $cutoff->toIso8601String(),
            $days,
            $count,
            $this->option('dry-run') ? '(dry-run)' : ''
        ));

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($cutoff): void {
            AuditEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->delete();
        });

        $this->info('audit:purge completed.');
        return self::SUCCESS;
    }
}

