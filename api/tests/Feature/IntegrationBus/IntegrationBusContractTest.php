<?php

declare(strict_types=1);

namespace Tests\Feature\IntegrationBus;

use App\Integrations\Bus\IntegrationBusEnvelope;
use App\Integrations\Bus\IntegrationBusValidator;
use App\Jobs\IntegrationBus\ProcessIntegrationBusMessage;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IntegrationBusContractTest extends TestCase
{
    #[Test]
    #[DataProvider('fixtureProvider')]
    public function envelope_fixtures_pass_schema_and_validator(string $fixturePath): void
    {
        $fixture = $this->loadFixture($fixturePath);

        $this->assertSchemaValid($fixture['envelopeObject']);

        $validator = new IntegrationBusValidator;
        $errors = $validator->validate($fixture['envelopeArray'], $fixture['headers']);
        self::assertSame([], $errors, 'Expected validator to accept fixture without errors.');

        $job = ProcessIntegrationBusMessage::fromArray($fixture['envelopeArray']);
        self::assertSame('integration-bus', $job->queue);

        $expected = $this->normalizeEnvelope($fixture['envelopeArray']);
        self::assertSame(
            $this->normalizeForComparison($expected),
            $this->normalizeForComparison($job->envelope->toArray())
        );
        self::assertSame($fixture['envelopeArray']['kind'], $job->envelope->kind);
        self::assertSame($fixture['envelopeArray']['connectorKey'], $job->envelope->connectorKey);
    }

    #[Test]
    public function queue_headers_must_match_envelope_body(): void
    {
        $fixture = $this->loadFixture($this->fixturePath('asset.discovery'));

        $validator = new IntegrationBusValidator;
        $headers = $fixture['headers'];
        $headers['x-phpgrc-kind'] = 'incident.event';

        $errors = $validator->validate($fixture['envelopeArray'], $headers);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('Header [x-phpgrc-kind] must equal envelope kind', $errors[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fixtureProvider(): array
    {
        $baseDir = self::fixturesDirectory();
        $files = glob($baseDir.'/*.json') ?: [];
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $cases[$name] = [$file];
        }

        return $cases;
    }

    /**
     * @return array{
     *     envelopeArray: array<string,mixed>,
     *     envelopeObject: object,
     *     headers: array<string,string>
     * }
     */
    private function loadFixture(string $path): array
    {
        $json = file_get_contents($path);
        self::assertIsString($json, sprintf('Unable to read fixture [%s]', $path));

        /**
         * @var array{
         *     envelope: array<string,mixed>,
         *     headers: array<string,string>
         * } $data
         */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $envelopeObject = json_decode((string) json_encode($data['envelope'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        return [
            'envelopeArray' => $data['envelope'],
            'envelopeObject' => $envelopeObject,
            'headers' => $data['headers'],
        ];
    }

    private function assertSchemaValid(object $envelope): void
    {
        $validator = new Validator;
        $schema = (object) [
            '$ref' => 'file://'.$this->schemaPath(),
        ];

        $validator->validate($envelope, $schema);
        $errors = $validator->getErrors();
        $messages = array_map(static fn (array $err): string => sprintf('%s: %s', $err['property'] ?? '(root)', $err['message'] ?? ''), $errors);

        self::assertTrue($validator->isValid(), implode(PHP_EOL, $messages));
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function normalizeEnvelope(array $envelope): array
    {
        $normalized = $envelope;
        if (($normalized['priority'] ?? IntegrationBusEnvelope::PRIORITY_NORMAL) === IntegrationBusEnvelope::PRIORITY_NORMAL) {
            unset($normalized['priority']);
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function normalizeForComparison(array $value): array
    {
        if ($value === []) {
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                if (is_array($item)) {
                    $value[$index] = $this->normalizeForComparison($item);
                }
            }

            return $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeForComparison($item);
            }
        }

        return $value;
    }

    private function schemaPath(): string
    {
        return $this->workspaceRoot().'/docs/integrations/integration-bus-envelope.schema.json';
    }

    private function fixturePath(string $name): string
    {
        return self::fixturesDirectory().'/'.$name.'.json';
    }

    private static function fixturesDirectory(): string
    {
        $path = __DIR__.'/../../Fixtures/IntegrationBus';

        if (is_dir($path)) {
            return $path;
        }

        $resolved = realpath($path);

        return $resolved !== false ? $resolved : $path;
    }

    private function projectRoot(): string
    {
        $path = __DIR__.'/../../..';
        $resolved = realpath($path);

        return $resolved !== false ? $resolved : $path;
    }

    private function workspaceRoot(): string
    {
        $project = $this->projectRoot();
        $path = $project.'/..';
        $resolved = realpath($path);

        return $resolved !== false ? $resolved : $path;
    }
}
