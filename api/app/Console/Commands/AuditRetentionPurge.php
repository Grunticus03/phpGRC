<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditRetentionPurge extends Command
{
    protected $signature = 'audit:purge 
        {--days= : Override retention days (min 30, max 730)} 
        {--dry-run : Report count only without deleting}';

    protected $description = 'Purge audit events older than retention window';

    public function handle(): int
    {
        $cfg = (int) config('core.audit.retention_days', 365);
        $daysOpt = $this->option('days');

        $days = is_numeric($daysOpt) ? (int) $daysOpt : $cfg;
        $days = max(30, min(730, $days));

        $cutoff = Carbon::now('UTC')->subDays($days);

        $this->line("Retention: {$days} days. Cutoff: {$cutoff->toAtomString()}");

        if ($this->option('dry-run')) {
            $count = DB::table('audit_events')
                ->where('occurred_at', '<', $cutoff)
                ->count();
            $this->info("Would purge: {$count} rows");
            return self::SUCCESS;
        }

        $total = 0;
        do {
            $deleted = DB::table('audit_events')
                ->where('occurred_at', '<', $cutoff)
                ->limit(10_000)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Purged: {$total} rows");
        return self::SUCCESS;
    }
}
