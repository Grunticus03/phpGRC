<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Kernel as AppConsoleKernel;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class AuditRetentionPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_registers_daily_job(): void
    {
        // Arrange config before schedule registration.
        Config::set('core.audit.enabled', true);
        Config::set('core.audit.retention_days', 365);

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        /** @var AppConsoleKernel $kernel */
        $kernel = $this->app->make(AppConsoleKernel::class);

        // Invoke protected Kernel::schedule($schedule) so events are registered in this test.
        $ref = new \ReflectionMethod(AppConsoleKernel::class, 'schedule');
        $ref->setAccessible(true);
        $ref->invoke($kernel, $schedule);

        $events = $schedule->events();
        $found = false;

        foreach ($events as $e) {
            $cmd = (string) ($e->command ?? '');
            $expr = property_exists($e, 'expression') ? (string) $e->expression : '';
            $tz = property_exists($e, 'timezone') ? $e->timezone : null;

            $tzOk = $tz instanceof \DateTimeZone ? ($tz->getName() === 'UTC') : ($tz === 'UTC' || $tz === null);

            if (
                str_contains($cmd, 'audit:purge')
                && str_contains($cmd, '--days=365')
                && $expr === '10 3 * * *'
                && $tzOk
            ) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Expected scheduled audit:purge at 03:10 UTC with --days=365');
    }

    public function test_invalid_days_is_rejected(): void
    {
        Config::set('core.audit.enabled', true);

        $code = Artisan::call('audit:purge', ['--days' => '5']); // below min 30
        self::assertSame(2 /* INVALID */, $code);

        $output = Artisan::output();
        self::assertStringContainsString('AUDIT_RETENTION_INVALID', $output);
    }

    public function test_dry_run_reports_candidates(): void
    {
        Config::set('core.audit.enabled', true);

        $now = CarbonImmutable::now('UTC');

        // Two old, one recent
        AuditEvent::query()->insert([
            [
                'id' => '01JOLD00000000000000000000',
                'occurred_at' => $now->subDays(400),
                'actor_id' => null,
                'action' => 'x',
                'category' => 'AUDIT',
                'entity_type' => 'x',
                'entity_id' => 'a',
                'ip' => null,
                'ua' => null,
                'meta' => [],
                'created_at' => $now,
            ],
            [
                'id' => '01JOLD00000000000000000001',
                'occurred_at' => $now->subDays(200),
                'actor_id' => null,
                'action' => 'y',
                'category' => 'AUDIT',
                'entity_type' => 'y',
                'entity_id' => 'b',
                'ip' => null,
                'ua' => null,
                'meta' => [],
                'created_at' => $now,
            ],
            [
                'id' => '01JRECENT00000000000000000',
                'occurred_at' => $now->subDays(10),
                'actor_id' => null,
                'action' => 'z',
                'category' => 'AUDIT',
                'entity_type' => 'z',
                'entity_id' => 'c',
                'ip' => null,
                'ua' => null,
                'meta' => [],
                'created_at' => $now,
            ],
        ]);

        $code = Artisan::call('audit:purge', ['--days' => '180', '--dry-run' => true]);
        self::assertSame(0, $code);
        $out = Artisan::output();

        self::assertStringContainsString('"dry_run":true', $out);
        self::assertStringContainsString('"days":180', $out);
        self::assertStringContainsString('"candidates":2', $out);
    }

    public function test_purge_deletes_and_emits_summary_when_enabled(): void
    {
        Config::set('core.audit.enabled', true);

        $now = CarbonImmutable::now('UTC');

        AuditEvent::query()->insert([
            [
                'id' => '01JOLD00000000000000000010',
                'occurred_at' => $now->subDays(400),
                'actor_id' => null,
                'action' => 'x',
                'category' => 'AUDIT',
                'entity_type' => 'x',
                'entity_id' => 'a',
                'ip' => null,
                'ua' => null,
                'meta' => [],
                'created_at' => $now,
            ],
            [
                'id' => '01JRECENT00000000000000011',
                'occurred_at' => $now->subDays(20),
                'actor_id' => null,
                'action' => 'y',
                'category' => 'AUDIT',
                'entity_type' => 'y',
                'entity_id' => 'b',
                'ip' => null,
                'ua' => null,
                'meta' => [],
                'created_at' => $now,
            ],
        ]);

        $code = Artisan::call('audit:purge', ['--days' => '180', '--emit-summary' => true]);
        self::assertSame(0, $code);

        $remaining = AuditEvent::query()->count();
        self::assertSame(2, $remaining, 'One recent + one summary event should remain');

        $summary = AuditEvent::query()
            ->where('action', 'audit.retention.purged')
            ->first();

        self::assertNotNull($summary);
        /** @var array<string,mixed> $meta */
        $meta = $summary->meta;
        self::assertSame(1, (int) ($meta['deleted'] ?? -1));
        self::assertSame(180, (int) ($meta['days'] ?? -1));
    }
}
