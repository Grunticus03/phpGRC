<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Events\SettingsUpdated;
use App\Listeners\Audit\RecordSettingsUpdate;
use App\Models\AuditEvent;
use App\Support\Audit\AuditCategories;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_keys_are_redacted_in_changes(): void
    {
        config(['core.audit.enabled' => true]);

        $listener = app(RecordSettingsUpdate::class);

        $when = CarbonImmutable::now('UTC');

        $changes = [
            [
                'key' => 'core.smtp.password',
                'old' => 'p1',
                'new' => 'p2',
                'action' => 'update',
            ],
            [
                'key' => 'core.metrics.cache_ttl_seconds',
                'old' => 0,
                'new' => 60,
                'action' => 'set',
            ],
        ];

        $evt = new SettingsUpdated(
            actorId: null,
            changes: $changes,
            context: ['ip' => '127.0.0.1', 'ua' => 'phpunit', 'reason' => 'unit'],
            occurredAt: $when
        );

        // Call listener directly
        $listener->handle($evt);

        $rows = AuditEvent::query()->where('action', 'setting.modified')->get();
        self::assertCount(2, $rows);

        $byKey = [];
        foreach ($rows as $row) {
            self::assertSame(AuditCategories::SETTINGS, $row->category);
            $meta = $row->meta ?? [];
            self::assertIsArray($meta);
            self::assertArrayHasKey('changes', $meta);
            $changes = is_array($meta['changes']) ? $meta['changes'] : [];
            self::assertCount(1, $changes);
            $change = $changes[0];
            self::assertArrayHasKey('key', $change);
            $byKey[$change['key']] = [$change, $meta];
        }

        self::assertArrayHasKey('core.smtp.password', $byKey);
        [$smtpChange, $smtpMeta] = $byKey['core.smtp.password'];
        self::assertSame('[REDACTED]', $smtpChange['old'] ?? '');
        self::assertSame('[REDACTED]', $smtpChange['new'] ?? '');
        self::assertSame('[REDACTED]', $smtpMeta['old_value'] ?? '');
        self::assertSame('[REDACTED]', $smtpMeta['new_value'] ?? '');

        self::assertArrayHasKey('core.metrics.cache_ttl_seconds', $byKey);
        [$metricChange, $metricMeta] = $byKey['core.metrics.cache_ttl_seconds'];
        self::assertSame(0, $metricChange['old'] ?? -1);
        self::assertSame(60, $metricChange['new'] ?? -1);
        self::assertSame('0', $metricMeta['old_value'] ?? '');
        self::assertSame('60', $metricMeta['new_value'] ?? '');
    }
}
