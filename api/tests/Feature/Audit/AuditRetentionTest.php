<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(string $id, CarbonImmutable $occurredAt): AuditEvent
    {
        return AuditEvent::query()->create([
            'id' => $id,
            'occurred_at' => $occurredAt,
            'actor_id' => null,
            'action' => 'test.event',
            'category' => 'AUDIT',
            'entity_type' => 'test',
            'entity_id' => 'fixture',
            'ip' => null,
            'ua' => null,
            'meta' => ['k' => 'v'],
            'created_at' => $occurredAt,
        ]);
    }

    /**
     * Build the Console schedule with current config and return command strings.
     *
     * @return array<int, string>
     */
    private function scheduledCommands(): array
    {
        /** @var \App\Console\Kernel $kernel */
        $kernel = $this->app->make(\App\Console\Kernel::class);

        // Use a fresh Schedule instance to avoid any prior state.
        $schedule = new Schedule($this->app);

        // Invoke protected Kernel::schedule() via reflection.
        $ref = new \ReflectionClass($kernel);
        $m = $ref->getMethod('schedule');
        $m->setAccessible(true);
        $m->invoke($kernel, $schedule);

        $commands = [];
        foreach ($schedule->events() as $event) {
            // Prefer buildCommand() if available, else fall back to $event->command.
            if (method_exists($event, 'buildCommand')) {
                /** @var string $cmd */
                $cmd = $event->buildCommand();
                $commands[] = $cmd;
            } elseif (property_exists($event, 'command') && is_string($event->command)) {
                /** @phpstan-ignore-next-line */
                $commands[] = $event->command;
            }
        }

        return $commands;
    }

    public function test_purge_deletes_only_older_than_cutoff_and_keeps_boundary(): void
    {
        Config::set('core.audit.enabled', true);

        $now = CarbonImmutable::create(2025, 9, 19, 6, 10, 0, 'UTC');
        CarbonImmutable::setTestNow($now);

        $cutoff = $now->subDays(365);

        $olderId = Str::ulid()->toBase32();
        $boundaryId = Str::ulid()->toBase32();
        $newerId = Str::ulid()->toBase32();

        $this->makeEvent($olderId, $cutoff->subSecond());  // delete
        $this->makeEvent($boundaryId, $cutoff);            // keep (strict <)
        $this->makeEvent($newerId, $cutoff->addSecond());  // keep

        $exit = Artisan::call('audit:purge', ['--days' => '365']);
        $out = Artisan::output();
        $json = json_decode($out, true);

        $this->assertSame(0, $exit, 'exit code');
        $this->assertIsArray($json);
        $this->assertTrue($json['ok'] ?? false);
        $this->assertSame(false, $json['dry_run'] ?? null);
        $this->assertSame(365, $json['days'] ?? null);
        $this->assertSame(1, $json['deleted'] ?? null);

        $this->assertDatabaseMissing('audit_events', ['id' => $olderId]);
        $this->assertDatabaseHas('audit_events', ['id' => $boundaryId]);
        $this->assertDatabaseHas('audit_events', ['id' => $newerId]);

        // Idempotent
        $exit2 = Artisan::call('audit:purge', ['--days' => '365']);
        $out2 = Artisan::output();
        $json2 = json_decode($out2, true);

        $this->assertSame(0, $exit2, 'second run exit code');
        $this->assertIsArray($json2);
        $this->assertTrue($json2['ok'] ?? false);
        $this->assertSame(0, $json2['deleted'] ?? null);
        $this->assertDatabaseHas('audit_events', ['id' => $boundaryId]);
        $this->assertDatabaseHas('audit_events', ['id' => $newerId]);
    }

    public function test_dry_run_reports_candidates_and_does_not_delete(): void
    {
        Config::set('core.audit.enabled', true);

        $now = CarbonImmutable::create(2025, 9, 19, 6, 20, 0, 'UTC');
        CarbonImmutable::setTestNow($now);

        $cutoff = $now->subDays(365);

        $del1 = Str::ulid()->toBase32();
        $del2 = Str::ulid()->toBase32();
        $keep = Str::ulid()->toBase32();

        $this->makeEvent($del1, $cutoff->subDay());
        $this->makeEvent($del2, $cutoff->subSecond());
        $this->makeEvent($keep, $cutoff->addDay());

        $exit = Artisan::call('audit:purge', ['--days' => '365', '--dry-run' => true]);
        $out = Artisan::output();
        $json = json_decode($out, true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($json);
        $this->assertTrue($json['ok'] ?? false);
        $this->assertTrue($json['dry_run'] ?? false);
        $this->assertSame(2, $json['candidates'] ?? null);

        $this->assertDatabaseHas('audit_events', ['id' => $del1]);
        $this->assertDatabaseHas('audit_events', ['id' => $del2]);
        $this->assertDatabaseHas('audit_events', ['id' => $keep]);
    }

    public function test_emit_summary_writes_a_summary_event(): void
    {
        Config::set('core.audit.enabled', true);

        $now = CarbonImmutable::create(2025, 9, 19, 6, 30, 0, 'UTC');
        CarbonImmutable::setTestNow($now);

        $cutoff = $now->subDays(365);

        $del = Str::ulid()->toBase32();
        $this->makeEvent($del, $cutoff->subSecond());

        $exit = Artisan::call('audit:purge', ['--days' => '365', '--emit-summary' => true]);
        $out = Artisan::output();
        $json = json_decode($out, true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($json);
        $this->assertSame(1, $json['deleted'] ?? null);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'audit.retention.purged',
            'category' => 'AUDIT',
            'entity_type' => 'audit',
            'entity_id' => 'retention',
        ]);
    }

    public function test_schedule_registers_purge_when_enabled(): void
    {
        Config::set('core.audit.enabled', true);
        Config::set('core.audit.retention_days', 365);

        $joined = implode("\n", $this->scheduledCommands());

        $this->assertStringContainsString('audit:purge', $joined);
        $this->assertStringContainsString('--days=365', $joined);
        $this->assertStringContainsString('--emit-summary', $joined);
    }

    public function test_schedule_omits_purge_when_disabled(): void
    {
        Config::set('core.audit.enabled', false);
        Config::set('core.audit.retention_days', 365);

        $joined = implode("\n", $this->scheduledCommands());

        $this->assertStringNotContainsString('audit:purge', $joined);
    }

    public function test_schedule_clamps_days_to_max_730(): void
    {
        Config::set('core.audit.enabled', true);
        Config::set('core.audit.retention_days', 99999); // beyond max

        $joined = implode("\n", $this->scheduledCommands());

        $this->assertStringContainsString('audit:purge', $joined);
        $this->assertStringContainsString('--days=730', $joined); // clamped
    }
}
