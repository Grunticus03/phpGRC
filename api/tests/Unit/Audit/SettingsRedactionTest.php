<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Events\SettingsUpdated;
use App\Listeners\Audit\RecordSettingsUpdate;
use App\Models\AuditEvent;
use App\Support\Audit\AuditCategories;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SettingsRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_keys_are_redacted_in_changes(): void
    {
        config(['core.audit.enabled' => true]);

        $listener = new RecordSettingsUpdate();

        $when = CarbonImmutable::now('UTC');

        $changes = [
            [
                'key'    => 'core.smtp.password',
                'old'    => 'p1',
                'new'    => 'p2',
                'action' => 'update',
            ],
            [
                'key'    => 'core.metrics.cache_ttl_seconds',
                'old'    => 0,
                'new'    => 60,
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

        /** @var AuditEvent|null $row */
        $row = AuditEvent::query()->where('action', 'settings.update')->orderByDesc('occurred_at')->first();
        static::assertNotNull($row, 'AuditEvent row should be written');
        static::assertSame(AuditCategories::SETTINGS, $row->category);

        $meta = $row->meta ?? [];
        static::assertIsArray($meta);
        static::assertArrayHasKey('changes', $meta);

        $made = [];
        foreach ((array) $meta['changes'] as $c) {
            $made[$c['key'] ?? ''] = $c;
        }

        static::assertArrayHasKey('core.smtp.password', $made);
        static::assertSame('[REDACTED]', $made['core.smtp.password']['old'] ?? '');
        static::assertSame('[REDACTED]', $made['core.smtp.password']['new'] ?? '');

        static::assertArrayHasKey('core.metrics.cache_ttl_seconds', $made);
        static::assertSame(0, $made['core.metrics.cache_ttl_seconds']['old'] ?? -1);
        static::assertSame(60, $made['core.metrics.cache_ttl_seconds']['new'] ?? -1);
    }
}

