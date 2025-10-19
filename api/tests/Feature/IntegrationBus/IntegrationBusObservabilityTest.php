<?php

declare(strict_types=1);

namespace Tests\Feature\IntegrationBus;

use App\Integrations\Bus\IntegrationBusEnvelope;
use App\Jobs\IntegrationBus\ProcessIntegrationBusMessage;
use App\Models\AuditEvent;
use App\Models\IntegrationConnector;
use App\Services\IntegrationBus\IntegrationBusDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IntegrationBusObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.audit.enabled', true);
        config()->set('core.rbac.enabled', false);
        config()->set('core.rbac.require_auth', false);
    }

    #[Test]
    public function it_emits_audit_and_updates_connector_meta(): void
    {
        $originalLogger = Log::getFacadeRoot();
        $logger = $this->newTestLogger();
        Log::swap($logger);

        $connector = IntegrationConnector::query()->create([
            'key' => 'aws-config',
            'name' => 'AWS Config',
            'kind' => 'asset.discovery',
            'enabled' => true,
            'config' => ['access_key' => 'AKIA', 'secret_key' => 'secret'],
        ]);

        $envelope = IntegrationBusEnvelope::fromArray($this->makeEnvelopePayload('asset.discovery', [
            'connectorKey' => $connector->key,
            'connectorVersion' => '2026.01.0',
            'meta' => ['window' => 'PT15M', 'feature' => 'inventory'],
            'attachments' => [
                [
                    'type' => 'log',
                    'uri' => 's3://bucket/key',
                    'contentType' => 'text/plain',
                ],
            ],
        ]));

        $job = new ProcessIntegrationBusMessage($envelope);
        try {
            $job->handle(app(IntegrationBusDispatcher::class));
        } finally {
            Log::swap($originalLogger);
        }

        /** @var AuditEvent|null $audit */
        $audit = AuditEvent::query()->first();
        self::assertNotNull($audit);
        self::assertSame('integration.bus.message.received', $audit->action);
        self::assertSame('INTEGRATION_BUS', $audit->category);
        self::assertSame($connector->key, $audit->entity_id);

        $auditMeta = $audit->meta ?? [];
        self::assertIsArray($auditMeta);
        self::assertSame($envelope->id, $auditMeta['envelope_id']);
        self::assertSame($envelope->kind, $auditMeta['kind']);
        self::assertSame($envelope->event, $auditMeta['event']);
        self::assertSame('processed', $auditMeta['status'] ?? null);
        self::assertSame(['assetId', 'name', 'type', 'environment', 'tags', 'attributes'], $auditMeta['payload_keys']);
        self::assertSame(['window', 'feature'], $auditMeta['meta_keys']);
        self::assertSame(1, $auditMeta['attachments']['count']);
        self::assertSame(['log'], $auditMeta['attachments']['types']);

        $connector->refresh();
        $observability = $connector->meta['observability'] ?? null;
        self::assertIsArray($observability);
        self::assertSame(1, $observability['total_received']);
        self::assertSame(1, $observability['per_kind']['asset.discovery'] ?? null);
        self::assertSame('processed', $observability['last_status']);
        self::assertSame('asset.discovery', $observability['last_kind']);
        self::assertSame($envelope->event, $observability['last_event']);
        self::assertSame(['log'], $observability['last_attachments']['types']);
        self::assertSame(['window', 'feature'], $observability['last_meta_hint']);

        $logEntry = $this->findLogEntry($logger->logs, 'integration.bus.message');
        self::assertNotNull($logEntry);
        self::assertSame('info', $logEntry['level']);
        self::assertSame('processed', $logEntry['context']['status'] ?? null);
        self::assertSame($envelope->id, $logEntry['context']['envelope_id'] ?? null);
        self::assertSame($envelope->connectorKey, $logEntry['context']['connector']['key'] ?? null);
    }

    #[Test]
    public function it_tracks_error_status_counts_without_connector_record(): void
    {
        $originalLogger = Log::getFacadeRoot();
        $logger = $this->newTestLogger();
        Log::swap($logger);

        $payload = $this->makeEnvelopePayload('incident.event', [
            'connectorKey' => 'pagerduty',
            'error' => [
                'code' => 'CONNECTOR_TIMEOUT',
                'attempt' => 2,
                'maxAttempts' => 5,
                'retryAt' => '2026-01-13T12:00:00Z',
            ],
        ]);

        $envelope = IntegrationBusEnvelope::fromArray($payload);

        $job = new ProcessIntegrationBusMessage($envelope);
        try {
            $job->handle(app(IntegrationBusDispatcher::class));
        } finally {
            Log::swap($originalLogger);
        }

        /** @var AuditEvent|null $audit */
        $audit = AuditEvent::query()->first();
        self::assertNotNull($audit);
        $meta = $audit->meta ?? [];
        self::assertSame('errored', $meta['status'] ?? null);
        self::assertSame('CONNECTOR_TIMEOUT', $meta['error']['code'] ?? null);
        self::assertSame(2, $meta['error']['attempt'] ?? null);

        self::assertNull(IntegrationConnector::query()->where('key', '=', 'pagerduty')->first());

        $logEntry = $this->findLogEntry($logger->logs, 'integration.bus.message');
        self::assertNotNull($logEntry);
        self::assertSame('errored', $logEntry['context']['status'] ?? null);
        self::assertSame('CONNECTOR_TIMEOUT', $logEntry['context']['error']['code'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeEnvelopePayload(string $kind, array $overrides = []): array
    {
        $base = [
            'id' => '01JB2T3K3SM6P9J0F6W8C2BT6A',
            'busVersion' => '1.0.0',
            'connectorKey' => 'aws-config',
            'connectorVersion' => '2026.01.1',
            'tenantId' => 'core.default',
            'runId' => '01JB2T3K1R0YKPXQKJSXH7752P',
            'kind' => $kind,
            'event' => match ($kind) {
                'asset.discovery' => 'asset.upserted',
                'asset.lifecycle' => 'asset.retired',
                'incident.event' => 'incident.updated',
                'vendor.profile' => 'vendor.synced',
                'indicator.metric' => 'indicator.calculated',
                'cyber.metric' => 'cyber.summary',
                'auth.provider' => 'auth.health',
                default => 'bus.event',
            },
            'emittedAt' => '2026-01-12T12:15:32Z',
            'payload' => $this->payloadForKind($kind),
            'provenance' => [
                'source' => 'demo-source',
                'externalId' => 'ext-123',
                'ingestedAt' => '2026-01-12T12:15:00Z',
                'schemaRef' => 'https://example.test/schema',
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForKind(string $kind): array
    {
        return match ($kind) {
            'asset.discovery' => [
                'assetId' => 'asset-1',
                'name' => 'Demo Asset',
                'type' => 'server',
                'environment' => 'production',
                'tags' => ['tier:web'],
                'attributes' => ['owner' => 'demo'],
            ],
            'asset.lifecycle' => [
                'assetId' => 'asset-1',
                'status' => 'retired',
                'effectiveAt' => '2026-01-12T12:00:00Z',
            ],
            'incident.event' => [
                'incidentId' => 'INC-1',
                'status' => 'TRIAGE',
                'severity' => 'HIGH',
                'summary' => 'Investigation in progress',
            ],
            'vendor.profile' => [
                'vendorId' => 'vendor-1',
                'name' => 'Demo Vendor',
                'category' => 'hosting',
            ],
            'indicator.metric' => [
                'indicatorKey' => 'risk.score',
                'window' => 'P1D',
                'value' => 0.75,
                'unit' => 'ratio',
                'context' => ['module' => 'risks'],
            ],
            'cyber.metric' => [
                'sourceType' => 'vulnerability_scanner',
                'assetKey' => 'asset-1',
                'observedAt' => '2026-01-12T12:00:00Z',
                'metrics' => ['criticalFindings' => 3],
            ],
            'auth.provider' => [
                'providerKey' => 'oidc-demo',
                'status' => 'healthy',
                'checkedAt' => '2026-01-12T12:10:00Z',
            ],
            default => ['kind' => $kind],
        };
    }

    /**
     * @param  list<array{level:string,message:string,context:array<string,mixed>}>  $logs
     */
    private function findLogEntry(array $logs, string $message): ?array
    {
        foreach ($logs as $entry) {
            if ($entry['message'] === $message) {
                return $entry;
            }
        }

        return null;
    }

    private function newTestLogger(): \Psr\Log\LoggerInterface
    {
        return new class implements \Psr\Log\LoggerInterface
        {
            /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
            public array $logs = [];

            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
