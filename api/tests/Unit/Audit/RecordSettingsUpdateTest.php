<?php

declare(strict_types=1);

namespace Tests\Unit\Audit;

use App\Events\SettingsUpdated;
use App\Listeners\Audit\RecordSettingsUpdate;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class RecordSettingsUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_redacts_binary_payloads_before_logging(): void
    {
        Config::set('core.audit.enabled', true);

        $listener = new RecordSettingsUpdate(new AuditLogger);

        $binary = base64_encode(random_bytes(96));
        $dataUri = 'data:image/png;base64,'.$binary;

        $event = new SettingsUpdated(
            actorId: null,
            changes: [[
                'key' => 'ui.brand.primary_logo_asset_id',
                'old' => null,
                'new' => $dataUri,
                'action' => 'update',
            ]],
            context: [
                'ip' => '127.0.0.1',
                'ua' => 'phpunit',
            ],
            occurredAt: CarbonImmutable::now(),
        );

        $listener->handle($event);

        /** @var AuditEvent|null $audit */
        $audit = AuditEvent::query()->orderByDesc('occurred_at')->first();
        self::assertNotNull($audit);
        self::assertSame('ui.brand.updated', $audit->action);

        /** @var array<string,mixed>|null $meta */
        $meta = $audit->getAttribute('meta');
        self::assertIsArray($meta);
        self::assertSame('[binary omitted]', $meta['new_value'] ?? null);

        $changes = $meta['changes'] ?? null;
        self::assertIsArray($changes);
        self::assertIsArray($changes[0] ?? null);
        self::assertSame('[binary omitted]', $changes[0]['new'] ?? null);

        $message = $meta['message'] ?? '';
        self::assertIsString($message);
        self::assertStringContainsString('[binary omitted]', $message);
    }
}
