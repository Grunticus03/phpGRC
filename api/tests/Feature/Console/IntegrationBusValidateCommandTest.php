<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IntegrationBusValidateCommandTest extends TestCase
{
    #[Test]
    public function it_validates_envelope_successfully(): void
    {
        $envelope = $this->makeEnvelope('asset.discovery');
        $headers = $this->headersFor($envelope);

        $envelopePath = $this->writeJsonTempFile($envelope);
        $headersPath = $this->writeJsonTempFile($headers);

        try {
            $result = Artisan::call('integration-bus:validate', [
                'envelope' => $envelopePath,
                '--headers' => $headersPath,
            ]);

            $output = Artisan::output();
            self::assertSame(0, $result);
            self::assertStringContainsString('validation passed', strtolower($output));
        } finally {
            @unlink($envelopePath);
            @unlink($headersPath);
        }
    }

    #[Test]
    public function it_reports_errors_when_validation_fails(): void
    {
        $envelope = $this->makeEnvelope('asset.discovery');
        unset($envelope['payload']['name']);

        $envelopePath = $this->writeJsonTempFile($envelope);

        try {
            $result = Artisan::call('integration-bus:validate', [
                'envelope' => $envelopePath,
            ]);

            $output = Artisan::output();
            self::assertSame(1, $result);
            self::assertStringContainsString('missing payload field [name]', strtolower($output));
        } finally {
            @unlink($envelopePath);
        }
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,string>
     */
    private function headersFor(array $envelope): array
    {
        return [
            'x-phpgrc-bus-version' => (string) $envelope['busVersion'],
            'x-phpgrc-connector' => (string) $envelope['connectorKey'],
            'x-phpgrc-kind' => (string) $envelope['kind'],
            'x-phpgrc-run-id' => (string) $envelope['runId'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeEnvelope(string $kind): array
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
                default => 'bus.event',
            },
            'emittedAt' => '2026-01-12T12:15:32Z',
            'payload' => [
                'assetId' => 'asset-1',
                'name' => 'Demo Asset',
                'type' => 'server',
                'environment' => 'production',
                'tags' => ['tier:web'],
                'attributes' => ['owner' => 'demo'],
            ],
            'provenance' => [
                'source' => 'demo-source',
                'externalId' => 'ext-123',
                'ingestedAt' => '2026-01-12T12:15:00Z',
                'schemaRef' => 'https://phpgrc.test/docs/integrations/integration-bus-envelope.schema.json#/$defs/payloadAssetDiscovery',
            ],
        ];

        return $base;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function writeJsonTempFile(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bus-');
        if ($path === false) {
            self::fail('Unable to create temp file.');
        }

        file_put_contents($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
