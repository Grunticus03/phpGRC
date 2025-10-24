<?php

declare(strict_types=1);

namespace Tests\Feature\IntegrationBus;

use App\Events\IntegrationBus\AssetDiscoveryMessageReceived;
use App\Events\IntegrationBus\AssetLifecycleMessageReceived;
use App\Events\IntegrationBus\AuthProviderMessageReceived;
use App\Events\IntegrationBus\CyberMetricMessageReceived;
use App\Events\IntegrationBus\IncidentEventMessageReceived;
use App\Events\IntegrationBus\IndicatorMetricMessageReceived;
use App\Events\IntegrationBus\VendorProfileMessageReceived;
use App\Integrations\Bus\IntegrationBusEnvelope;
use App\Jobs\IntegrationBus\ProcessIntegrationBusMessage;
use App\Services\IntegrationBus\IntegrationBusDispatcher;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(ProcessIntegrationBusMessage::class)]
#[CoversClass(IntegrationBusDispatcher::class)]
final class ProcessIntegrationBusMessageTest extends TestCase
{
    #[DataProvider('kindProvider')]
    public function test_it_dispatches_expected_event_for_kind(string $kind, string $expectedEvent): void
    {
        Event::fake();

        $envelope = IntegrationBusEnvelope::fromArray($this->makeEnvelopePayload($kind));

        $job = new ProcessIntegrationBusMessage($envelope);
        $job->handle(app(IntegrationBusDispatcher::class));

        Event::assertDispatched($expectedEvent, function ($event) use ($envelope): bool {
            return $event->envelope === $envelope;
        });
    }

    public function test_unknown_kind_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Integration Bus kind [unknown.kind]');

        $envelope = IntegrationBusEnvelope::fromArray($this->makeEnvelopePayload('unknown.kind'));

        $job = new ProcessIntegrationBusMessage($envelope);
        $job->handle(app(IntegrationBusDispatcher::class));
    }

    public function test_events_can_be_faked_directly(): void
    {
        Event::fake();

        $envelope = IntegrationBusEnvelope::fromArray($this->makeEnvelopePayload('asset.discovery'));

        AssetDiscoveryMessageReceived::dispatch($envelope);

        Event::assertDispatched(AssetDiscoveryMessageReceived::class);
    }

    /**
     * @return array<string, array{string,class-string}>
     */
    public static function kindProvider(): array
    {
        return [
            'asset discovery' => ['asset.discovery', AssetDiscoveryMessageReceived::class],
            'asset lifecycle' => ['asset.lifecycle', AssetLifecycleMessageReceived::class],
            'incident event' => ['incident.event', IncidentEventMessageReceived::class],
            'vendor profile' => ['vendor.profile', VendorProfileMessageReceived::class],
            'indicator metric' => ['indicator.metric', IndicatorMetricMessageReceived::class],
            'cyber metric' => ['cyber.metric', CyberMetricMessageReceived::class],
            'auth provider' => ['auth.provider', AuthProviderMessageReceived::class],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeEnvelopePayload(string $kind): array
    {
        $base = [
            'id' => '01JB1K83J3H9QF9P3T0Y3PG1YD',
            'busVersion' => '1.0.0',
            'connectorKey' => 'demo-connector',
            'connectorVersion' => '0.1.0',
            'tenantId' => 'core.default',
            'runId' => '01JB1K80QPJVDK2XYRX2G5V7WZ',
            'kind' => $kind,
            'event' => match ($kind) {
                'asset.discovery' => 'asset.upserted',
                'asset.lifecycle' => 'asset.retired',
                'incident.event' => 'incident.updated',
                'vendor.profile' => 'vendor.synced',
                'indicator.metric' => 'indicator.calculated',
                'cyber.metric' => 'cyber.summary',
                'auth.provider' => 'auth.health',
                default => 'unknown.event',
            },
            'emittedAt' => '2026-01-12T12:15:32.000Z',
            'payload' => $this->payloadForKind($kind),
            'provenance' => [
                'source' => 'demo',
                'externalId' => 'ext-123',
                'ingestedAt' => '2026-01-12T12:15:30.000Z',
                'schemaRef' => 'https://example.test/schema',
            ],
        ];

        return $base;
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
                'effectiveAt' => '2026-01-12T12:00:00.000Z',
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
                'contacts' => [
                    [
                        'name' => 'Jane Doe',
                        'email' => 'jane@example.test',
                        'role' => 'manager',
                    ],
                ],
                'services' => [
                    [
                        'name' => 'Hosting',
                        'description' => 'Demo hosting service',
                        'criticality' => 'HIGH',
                    ],
                ],
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
                'observedAt' => '2026-01-12T12:00:00.000Z',
                'metrics' => ['criticalFindings' => 3],
            ],
            'auth.provider' => [
                'providerKey' => 'oidc-demo',
                'status' => 'healthy',
                'checkedAt' => '2026-01-12T12:10:00.000Z',
            ],
            default => ['unknown' => true],
        };
    }
}
