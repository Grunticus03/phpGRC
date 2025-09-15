<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\AuditRetentionPurge;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

final class Kernel extends ConsoleKernel
{
    /**
     * @var array<class-string>
     */
    protected $commands = [
        AuditRetentionPurge::class,
    ];

    #[\Override]
    protected function schedule(Schedule $schedule): void
    {
        if (!config('core.audit.enabled', true)) {
            return;
        }

        // Clamp to [30, 730] days to prevent accidental data loss.
        $days = (int) config('core.audit.retention_days', 365);
        $days = max(30, min(730, $days));

        $schedule->command("audit:purge --days={$days}")
            ->dailyAt('03:10')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
    }

    #[\Override]
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        $console = base_path('routes/console.php');
        if (is_file($console)) {
            require $console;
        }
    }
}

