<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Bus;

use App\Integrations\Bus\IntegrationBusEnvelope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntegrationBusEnvelopeTest extends TestCase
{
    #[Test]
    public function it_constructs_with_optional_fields(): void
    {
        $input = $this->baseEnvelope();
        $input['receivedAt'] = '2026-02-02T12:34:56Z';
        $input['priority'] = IntegrationBusEnvelope::PRIORITY_LOW;
        $input['attachments'] = [
            [
                'type' => 'report',
                'uri' => 's3://bucket/report.csv',
                'contentType' => 'text/csv',
                'size' => 1024,
            ],
        ];
        $input['meta'] = ['window' => 'PT15M'];
        $input['error'] = [
            'code' => 'CONNECTOR_TIMEOUT',
            'message' => 'Timed out waiting for connector response.',
            'occurredAt' => '2026-02-02T12:34:58Z',
            'attempt' => 2,
            'maxAttempts' => 5,
        ];

        $envelope = IntegrationBusEnvelope::fromArray($input);

        self::assertSame('01JBUGB0N5VFMJS2K79KJYBDCF', $envelope->id);
        self::assertSame('asset.discovery', $envelope->kind);
        self::assertSame(IntegrationBusEnvelope::PRIORITY_LOW, $envelope->priority);
        self::assertSame('2026-02-02T12:34:56Z', $envelope->receivedAt);
        self::assertCount(1, $envelope->attachments);
        self::assertSame('report', $envelope->attachments[0]['type'] ?? null);
        self::assertSame('PT15M', $envelope->meta['window'] ?? null);
        self::assertSame('CONNECTOR_TIMEOUT', $envelope->error['code'] ?? null);

        self::assertSame($input, $envelope->toArray());
    }

    #[Test]
    public function it_rejects_invalid_priority_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Integration Bus envelope contains invalid priority [urgent]');

        $input = $this->baseEnvelope();
        $input['priority'] = 'urgent';

        IntegrationBusEnvelope::fromArray($input);
    }

    #[Test]
    public function it_rejects_attachments_without_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Integration Bus attachment requires non-empty string [type]');

        $input = $this->baseEnvelope();
        $input['attachments'] = [
            [
                'uri' => 's3://bucket/report.csv',
            ],
        ];

        IntegrationBusEnvelope::fromArray($input);
    }

    /**
     * @return array<string,mixed>
     */
    private function baseEnvelope(): array
    {
        return [
            'id' => '01JBUGB0N5VFMJS2K79KJYBDCF',
            'busVersion' => '1.0.0',
            'connectorKey' => 'demo-connector',
            'connectorVersion' => '2026.01.0',
            'tenantId' => 'core.default',
            'runId' => '01JBUGB50N5G4ZTR6D5G3SDZHT',
            'kind' => 'asset.discovery',
            'event' => 'asset.upserted',
            'emittedAt' => '2026-02-02T12:34:56Z',
            'payload' => [
                'assetId' => 'asset-1',
                'name' => 'Demo Asset',
                'type' => 'server',
                'environment' => 'production',
                'tags' => ['tier:web'],
                'attributes' => ['owner' => 'platform'],
            ],
            'provenance' => [
                'source' => 'demo',
                'externalId' => 'demo-1',
                'ingestedAt' => '2026-02-02T12:34:50Z',
                'schemaRef' => 'https://phpgrc.internal/docs/integrations/integration-bus-envelope.schema.json#/$defs/payloadAssetDiscovery',
            ],
        ];
    }
}
