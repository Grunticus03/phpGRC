<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\AuditRetentionPurge;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

final class Kernel extends ConsoleKernel
{
    /**
     * Register Artisan commands.
     *
     * @var array<class-string>
     */
    protected $commands = [
        AuditRetentionPurge::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
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

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        $console = base_path('routes/console.php');
        if (is_file($console)) {
            require $console;
        }
    }
}

