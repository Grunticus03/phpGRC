<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\AuditRetentionPurge;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

final class Kernel extends ConsoleKernel
{
    /**
     * @var array<array-key, mixed>
     */
    protected $commands = [
        AuditRetentionPurge::class,
    ];

    #[\Override]
    protected function schedule(Schedule $schedule): void
    {
        // Register only when enabled (tests assert omission when disabled).
        $enabled = self::boolFrom(config('core.audit.enabled'), true);
        if (! $enabled) {
            return;
        }

        $days = self::clampDays(self::intFrom(config('core.audit.retention_days'), 365));

        $event = $schedule->command("audit:purge --days={$days} --emit-summary")
            ->dailyAt('03:10')
            ->timezone('UTC');

        // Avoid DB-backed cache locks during tests.
        if (! app()->environment('testing')) {
            $event->withoutOverlapping()->onOneServer()->runInBackground();
        }
    }

    #[\Override]
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        $console = base_path('routes/console.php');
        if (is_file($console)) {
            /** @psalm-suppress UnresolvableInclude */
            require $console;
        }
    }

    private static function clampDays(int $days): int
    {
        if ($days < 30) {
            return 30;
        }
        if ($days > 730) {
            return 730;
        }

        return $days;
    }

    private static function boolFrom(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $v = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

            return $v ?? $default;
        }

        return $default;
    }

    /**
     * Normalize numeric-ish values into an int.
     */
    private static function intFrom(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $t = trim($value);
            if ($t !== '' && preg_match('/^-?\d+$/', $t) === 1) {
                return (int) $t;
            }
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $default;
    }
}
