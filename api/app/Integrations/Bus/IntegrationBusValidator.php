<?php

declare(strict_types=1);

namespace App\Integrations\Bus;

use InvalidArgumentException;

/**
 * Lightweight validation harness for Integration Bus envelopes.
 *
 * Validates base envelope structure (via IntegrationBusEnvelope), per-kind payload requirements,
 * provenance schema references, and optional queue headers.
 */
final class IntegrationBusValidator
{
    /**
     * @var array<string, string>
     */
    private const REQUIRED_HEADERS = [
        'x-phpgrc-bus-version' => 'busVersion',
        'x-phpgrc-connector' => 'connectorKey',
        'x-phpgrc-kind' => 'kind',
        'x-phpgrc-run-id' => 'runId',
    ];

    /**
     * @var array<string, array{schemaRefFragment: string, requiredPayload: list<string>}>
     */
    private const KIND_RULES = [
        'asset.discovery' => [
            'schemaRefFragment' => '/$defs/payloadAssetDiscovery',
            'requiredPayload' => ['assetId', 'name', 'type', 'environment', 'tags', 'attributes'],
        ],
        'asset.lifecycle' => [
            'schemaRefFragment' => '/$defs/payloadAssetLifecycle',
            'requiredPayload' => ['assetId', 'status', 'effectiveAt'],
        ],
        'incident.event' => [
            'schemaRefFragment' => '/$defs/payloadIncidentEvent',
            'requiredPayload' => ['incidentId', 'status', 'severity', 'summary'],
        ],
        'vendor.profile' => [
            'schemaRefFragment' => '/$defs/payloadVendorProfile',
            'requiredPayload' => ['vendorId', 'name', 'category'],
        ],
        'indicator.metric' => [
            'schemaRefFragment' => '/$defs/payloadIndicatorMetric',
            'requiredPayload' => ['indicatorKey', 'window', 'value', 'unit', 'context'],
        ],
        'cyber.metric' => [
            'schemaRefFragment' => '/$defs/payloadCyberMetric',
            'requiredPayload' => ['sourceType', 'assetKey', 'observedAt', 'metrics'],
        ],
        'auth.provider' => [
            'schemaRefFragment' => '/$defs/payloadAuthProvider',
            'requiredPayload' => ['providerKey', 'status', 'checkedAt'],
        ],
    ];

    /**
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $headers
     * @return list<string>
     */
    public function validate(array $envelope, array $headers = []): array
    {
        $errors = [];

        try {
            $busEnvelope = IntegrationBusEnvelope::fromArray($envelope);
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();

            return $errors;
        }

        $errors = array_merge(
            $errors,
            $this->validateKindRules($busEnvelope),
            $this->validateSchemaRef($busEnvelope)
        );

        if ($headers !== []) {
            $errors = array_merge($errors, $this->validateHeaders($busEnvelope, $headers));
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return list<string>
     */
    private function validateSchemaRef(IntegrationBusEnvelope $envelope): array
    {
        $rules = self::KIND_RULES[$envelope->kind] ?? null;
        if ($rules === null) {
            return [];
        }

        $schemaRef = $envelope->provenance['schemaRef'] ?? null;
        if (! is_string($schemaRef) || $schemaRef === '') {
            return ['Provenance schemaRef must be a non-empty string.'];
        }

        $fragment = $this->extractSchemaFragment($schemaRef);
        $expected = $rules['schemaRefFragment'];
        if ($fragment !== $expected) {
            return [sprintf(
                'schemaRef fragment mismatch: expected [%s] but found [%s].',
                $expected,
                $fragment ?? '(none)'
            )];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateKindRules(IntegrationBusEnvelope $envelope): array
    {
        $rules = self::KIND_RULES[$envelope->kind] ?? null;
        if ($rules === null) {
            return [sprintf('Unsupported kind [%s]; add validation rules before shipping.', $envelope->kind)];
        }

        $errors = [];
        foreach ($rules['requiredPayload'] as $field) {
            if (! array_key_exists($field, $envelope->payload)) {
                $errors[] = sprintf('Missing payload field [%s] for kind [%s].', $field, $envelope->kind);
            }
        }

        $errors = array_merge($errors, $this->validateKindSpecific($envelope->kind, $envelope->payload));

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $headers
     * @return list<string>
     */
    private function validateHeaders(IntegrationBusEnvelope $envelope, array $headers): array
    {
        $errors = [];
        $normalized = $this->normalizeHeaders($headers);

        foreach (self::REQUIRED_HEADERS as $header => $property) {
            if (! array_key_exists($header, $normalized)) {
                $errors[] = sprintf('Missing required header [%s].', $header);

                continue;
            }

            $bodyValue = $this->envelopePropertyAsString($envelope, $property);
            if ($bodyValue !== null && $normalized[$header] !== $bodyValue) {
                $errors[] = sprintf(
                    'Header [%s] must equal envelope %s [%s]; received [%s].',
                    $header,
                    $property,
                    $bodyValue,
                    $normalized[$header]
                );
            }
        }

        if (array_key_exists('x-phpgrc-priority', $normalized)) {
            if ($normalized['x-phpgrc-priority'] !== $envelope->priority) {
                $errors[] = sprintf(
                    'Header [x-phpgrc-priority] must equal envelope priority [%s]; received [%s].',
                    $envelope->priority,
                    $normalized['x-phpgrc-priority']
                );
            }
        }

        if (array_key_exists('x-phpgrc-correlation', $normalized)) {
            $correlation = $envelope->provenance['correlationId'] ?? null;
            if ($correlation === null || $correlation === '') {
                $errors[] = 'Header [x-phpgrc-correlation] supplied but provenance.correlationId is missing.';
            } elseif ($normalized['x-phpgrc-correlation'] !== $correlation) {
                $errors[] = sprintf(
                    'Header [x-phpgrc-correlation] must equal provenance.correlationId [%s]; received [%s].',
                    $correlation,
                    $normalized['x-phpgrc-correlation']
                );
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateKindSpecific(string $kind, array $payload): array
    {
        return match ($kind) {
            'asset.discovery' => $this->validateAssetDiscovery($payload),
            'incident.event' => $this->validateIncidentEvent($payload),
            default => [],
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateAssetDiscovery(array $payload): array
    {
        $errors = [];

        if (array_key_exists('tags', $payload) && ! is_array($payload['tags'])) {
            $errors[] = 'Payload field [tags] must be an array.';
        }

        if (array_key_exists('attributes', $payload) && ! is_array($payload['attributes'])) {
            $errors[] = 'Payload field [attributes] must be an object/dictionary.';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function validateIncidentEvent(array $payload): array
    {
        $errors = [];
        $allowedStatuses = ['NEW', 'TRIAGE', 'CONTAINED', 'ERADICATED', 'RECOVERED', 'CLOSED'];
        /** @var mixed $statusValue */
        $statusValue = $payload['status'] ?? null;
        if ($statusValue !== null) {
            if (! is_string($statusValue)) {
                $errors[] = 'Payload field [status] must be a string.';
            } elseif (! in_array($statusValue, $allowedStatuses, true)) {
                $errors[] = sprintf(
                    'Incident status must be one of [%s]; received [%s].',
                    implode(', ', $allowedStatuses),
                    $statusValue
                );
            }
        }

        return $errors;
    }

    private function extractSchemaFragment(string $schemaRef): ?string
    {
        $parts = parse_url($schemaRef);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            return '/'.ltrim($parts['fragment'], '/');
        }

        $path = $parts['path'] ?? null;
        if (is_string($path) && str_starts_with($path, '#')) {
            return substr($path, 1);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $headers
     * @return array<string,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($headers as $key => $value) {
            $header = strtolower($key);

            if (is_array($value)) {
                /** @var list<mixed> $values */
                $values = array_values($value);
                /** @var mixed $first */
                $first = $values[0] ?? null;
                $normalized[$header] = is_scalar($first) ? (string) $first : '';
            } elseif (is_scalar($value)) {
                $normalized[$header] = (string) $value;
            } else {
                $normalized[$header] = '';
            }
        }

        return $normalized;
    }

    private function envelopePropertyAsString(IntegrationBusEnvelope $envelope, string $property): ?string
    {
        if (! property_exists($envelope, $property)) {
            return null;
        }

        /** @var mixed $value */
        $value = $envelope->{$property};

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
