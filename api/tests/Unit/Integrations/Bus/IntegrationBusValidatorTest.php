<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Bus;

use App\Integrations\Bus\IntegrationBusValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntegrationBusValidatorTest extends TestCase
{
    private IntegrationBusValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new IntegrationBusValidator;
    }

    #[Test]
    public function it_accepts_valid_envelope_and_headers(): void
    {
        $envelope = $this->makeEnvelopePayload('asset.discovery');
        $headers = $this->headersFor($envelope);

        $errors = $this->validator->validate($envelope, $headers);

        self::assertSame([], $errors);
    }

    #[Test]
    public function it_detects_missing_payload_fields(): void
    {
        $envelope = $this->makeEnvelopePayload('asset.discovery');
        unset($envelope['payload']['name']);

        $errors = $this->validator->validate($envelope, $this->headersFor($envelope));

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Missing payload field [name]', $errors[0]);
    }

    #[Test]
    public function it_detects_schema_ref_mismatch(): void
    {
        $envelope = $this->makeEnvelopePayload('indicator.metric');
        $envelope['provenance']['schemaRef'] = 'https://example.test/schema#/$defs/payloadWrong';

        $errors = $this->validator->validate($envelope, $this->headersFor($envelope));

        self::assertNotEmpty($errors);
        self::assertStringContainsString('schemaRef fragment mismatch', $errors[0]);
    }

    #[Test]
    public function it_detects_priority_header_mismatch(): void
    {
        $envelope = $this->makeEnvelopePayload('asset.discovery');
        $envelope['priority'] = 'high';

        $headers = $this->headersFor($envelope);
        $headers['x-phpgrc-priority'] = 'low';

        $errors = $this->validator->validate($envelope, $headers);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('x-phpgrc-priority', implode(' ', $errors));
    }

    #[Test]
    public function it_requires_correlation_header_to_match_body(): void
    {
        $envelope = $this->makeEnvelopePayload('indicator.metric');
        $envelope['provenance']['correlationId'] = 'indicator-critical-daily';

        $headers = $this->headersFor($envelope);
        $headers['x-phpgrc-correlation'] = 'wrong-correlation';

        $errors = $this->validator->validate($envelope, $headers);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('x-phpgrc-correlation', implode(' ', $errors));

        $headers['x-phpgrc-correlation'] = 'indicator-critical-daily';
        $errors = $this->validator->validate($envelope, $headers);
        self::assertSame([], $errors);
    }

    #[Test]
    public function it_flags_missing_correlation_body_when_header_provided(): void
    {
        $envelope = $this->makeEnvelopePayload('vendor.profile');
        $headers = $this->headersFor($envelope);
        $headers['x-phpgrc-correlation'] = 'extra';

        $errors = $this->validator->validate($envelope, $headers);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('provenance.correlationId is missing', implode(' ', $errors));
    }

    #[Test]
    public function it_normalizes_array_header_values(): void
    {
        $envelope = $this->makeEnvelopePayload('asset.discovery');
        $headers = [
            'x-phpgrc-bus-version' => [$envelope['busVersion'], 'ignore'],
            'x-phpgrc-connector' => [$envelope['connectorKey']],
            'x-phpgrc-kind' => [$envelope['kind']],
            'x-phpgrc-run-id' => [$envelope['runId']],
        ];

        $errors = $this->validator->validate($envelope, $headers);
        self::assertSame([], $errors);
    }

    #[Test]
    public function it_detects_header_mismatch(): void
    {
        $envelope = $this->makeEnvelopePayload('incident.event');
        $headers = $this->headersFor($envelope);
        $headers['x-phpgrc-run-id'] = '01ZZZZZZZZZZZZZZZZZZZZZZZZ';

        $errors = $this->validator->validate($envelope, $headers);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('x-phpgrc-run-id', $errors[0]);
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,string>
     */
    private function headersFor(array $envelope): array
    {
        $headers = [
            'x-phpgrc-bus-version' => (string) $envelope['busVersion'],
            'x-phpgrc-connector' => (string) $envelope['connectorKey'],
            'x-phpgrc-kind' => (string) $envelope['kind'],
            'x-phpgrc-run-id' => (string) $envelope['runId'],
        ];

        if (isset($envelope['priority'])) {
            $headers['x-phpgrc-priority'] = (string) $envelope['priority'];
        }

        $correlation = $envelope['provenance']['correlationId'] ?? null;
        if (is_string($correlation) && $correlation !== '') {
            $headers['x-phpgrc-correlation'] = $correlation;
        }

        return $headers;
    }

    /**
     * @return array<string,mixed>
     */
    private function makeEnvelopePayload(string $kind): array
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
                'schemaRef' => sprintf(
                    'https://phpgrc.test/docs/integrations/integration-bus-envelope.schema.json#/$defs/%s',
                    $this->schemaFragmentForKind($kind)
                ),
            ],
        ];

        return $base;
    }

    /**
     * @return array<string,mixed>
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

    private function schemaFragmentForKind(string $kind): string
    {
        return match ($kind) {
            'asset.discovery' => 'payloadAssetDiscovery',
            'asset.lifecycle' => 'payloadAssetLifecycle',
            'incident.event' => 'payloadIncidentEvent',
            'vendor.profile' => 'payloadVendorProfile',
            'indicator.metric' => 'payloadIndicatorMetric',
            'cyber.metric' => 'payloadCyberMetric',
            'auth.provider' => 'payloadAuthProvider',
            default => 'payloadUnknown',
        };
    }
}
