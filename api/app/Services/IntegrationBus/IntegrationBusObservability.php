<?php

declare(strict_types=1);

namespace App\Services\IntegrationBus;

use App\Integrations\Bus\IntegrationBusEnvelope;
use App\Models\AuditEvent;
use App\Models\IntegrationConnector;
use App\Support\Audit\AuditCategories;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Captures observability signals for Integration Bus envelopes by emitting audit events
 * and updating per-connector telemetry metadata.
 */
final class IntegrationBusObservability
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function recordReceived(IntegrationBusEnvelope $envelope): void
    {
        $receivedAt = CarbonImmutable::now('UTC');

        $this->recordAuditEvent($envelope, $receivedAt);
        $this->updateConnectorObservability($envelope, $receivedAt);
    }

    private function recordAuditEvent(IntegrationBusEnvelope $envelope, CarbonImmutable $receivedAt): void
    {
        if (! Config::get('core.audit.enabled', true)) {
            return;
        }
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        AuditEvent::query()->insert([
            'id' => (string) Str::ulid(),
            'occurred_at' => $receivedAt,
            'actor_id' => null,
            'action' => 'integration.bus.message.received',
            'category' => AuditCategories::INTEGRATION_BUS,
            'entity_type' => 'integration_bus.connector',
            'entity_id' => $envelope->connectorKey,
            'ip' => null,
            'ua' => null,
            'meta' => $this->buildAuditMeta($envelope, $receivedAt),
            'created_at' => $receivedAt,
        ]);
    }

    private function updateConnectorObservability(IntegrationBusEnvelope $envelope, CarbonImmutable $receivedAt): void
    {
        if (! Schema::hasTable('integration_connectors')) {
            return;
        }

        $this->db->transaction(function () use ($envelope, $receivedAt): void {
            /** @var IntegrationConnector|null $connector */
            $connector = IntegrationConnector::query()
                ->where('integration_connectors.key', '=', $envelope->connectorKey)
                ->lockForUpdate()
                ->first();

            if ($connector === null) {
                return;
            }

            /** @var array<string, mixed> $meta */
            $meta = $this->ensureAssocArray($connector->meta);
            /** @var array<string, mixed> $observability */
            $observability = $this->ensureAssocArray($meta['observability'] ?? []);
            /** @var array<string, int> $perKind */
            $perKind = $this->ensureStringIntMap($observability['per_kind'] ?? []);
            /** @var array<string, int> $statusCounts */
            $statusCounts = $this->ensureStringIntMap($observability['status_counts'] ?? []);

            $perKind[$envelope->kind] = ($perKind[$envelope->kind] ?? 0) + 1;

            $status = $envelope->error === null ? 'processed' : 'errored';
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            $observability = [
                'total_received' => ($this->intValue($observability['total_received'] ?? null)) + 1,
                'per_kind' => $perKind,
                'status_counts' => $statusCounts,
                'last_envelope_id' => $envelope->id,
                'last_kind' => $envelope->kind,
                'last_event' => $envelope->event,
                'last_run_id' => $envelope->runId,
                'last_priority' => $envelope->priority,
                'last_status' => $status,
                'last_received_at' => $receivedAt->toIso8601String(),
                'last_emitted_at' => $envelope->emittedAt,
                'last_source' => [
                    'source' => $envelope->provenance['source'],
                    'external_id' => $envelope->provenance['externalId'],
                    'ingested_at' => $envelope->provenance['ingestedAt'],
                    'correlation_id' => $envelope->provenance['correlationId'] ?? null,
                    'replay' => $this->boolValue($envelope->provenance['replay'] ?? false),
                ],
            ];

            if ($envelope->meta !== []) {
                $observability['last_meta_hint'] = $this->summarizeKeys($envelope->meta);
            }

            if ($envelope->attachments !== []) {
                $observability['last_attachments'] = [
                    'count' => count($envelope->attachments),
                    'types' => $this->collectAttachmentTypes($envelope->attachments),
                ];
            }

            $meta['observability'] = $observability;
            $connector->meta = $meta;
            $connector->last_health_at = $receivedAt;
            $connector->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureAssocArray(mixed $input): array
    {
        if (is_array($input)) {
            /** @var array<array-key, mixed> $arrayInput */
            $arrayInput = $input;
            /** @var array<string, mixed> $normalized */
            $normalized = [];
            /** @psalm-suppress MixedAssignment */
            foreach ($arrayInput as $key => $value) {
                $normalized[(string) $key] = $value;
            }

            return $normalized;
        }

        if ($input instanceof \ArrayObject) {
            /** @var array<string, mixed> $copy */
            $copy = $this->ensureAssocArray($input->getArrayCopy());

            return $copy;
        }

        if ($input instanceof \Traversable) {
            /** @var array<string, mixed> $iterated */
            $iterated = $this->ensureAssocArray(iterator_to_array($input));

            return $iterated;
        }

        if ($input === null) {
            return [];
        }

        if (is_object($input)) {
            /** @var array<string, mixed> $cast */
            $cast = $this->ensureAssocArray((array) $input);

            return $cast;
        }

        if (is_scalar($input)) {
            return [];
        }

        return [];
    }

    /**
     * @return array<string, int>
     */
    private function ensureStringIntMap(mixed $input): array
    {
        $assoc = $this->ensureAssocArray($input);
        /** @var array<string, mixed> $entries */
        $entries = $assoc;
        /** @var array<string, int> $out */
        $out = [];

        /** @psalm-suppress MixedAssignment */
        foreach ($entries as $key => $value) {
            if (is_int($value)) {
                $out[$key] = $value;

                continue;
            }

            if (is_string($value) && ctype_digit($value)) {
                $out[$key] = (int) $value;

                continue;
            }

            if (is_float($value)) {
                $out[$key] = (int) $value;
            }
        }

        return $out;
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(ltrim($value, '+'))) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return false;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * @param  list<mixed>  $attachments
     * @return list<string>
     */
    private function collectAttachmentTypes(array $attachments): array
    {
        $types = [];
        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            if (! array_key_exists('type', $attachment)) {
                continue;
            }
            $type = $attachment['type'];
            if (! is_string($type) || $type === '') {
                continue;
            }
            $types[] = $type;
        }

        return array_values(array_unique($types));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAuditMeta(IntegrationBusEnvelope $envelope, CarbonImmutable $receivedAt): array
    {
        $payloadKeys = array_slice(array_keys($envelope->payload), 0, 15);

        return [
            'envelope_id' => $envelope->id,
            'bus_version' => $envelope->busVersion,
            'connector_version' => $envelope->connectorVersion,
            'tenant_id' => $envelope->tenantId,
            'run_id' => $envelope->runId,
            'kind' => $envelope->kind,
            'event' => $envelope->event,
            'priority' => $envelope->priority,
            'emitted_at' => $envelope->emittedAt,
            'received_at' => $receivedAt->toIso8601String(),
            'payload_keys' => $payloadKeys,
            'payload_size' => count($envelope->payload),
            'meta_keys' => $this->summarizeKeys($envelope->meta),
            'attachments' => [
                'count' => count($envelope->attachments),
                'types' => $this->collectAttachmentTypes($envelope->attachments),
            ],
            'provenance' => [
                'source' => $envelope->provenance['source'],
                'external_id' => $envelope->provenance['externalId'],
                'ingested_at' => $envelope->provenance['ingestedAt'],
                'schema_ref' => $envelope->provenance['schemaRef'],
                'correlation_id' => $envelope->provenance['correlationId'] ?? null,
                'replay' => $this->boolValue($envelope->provenance['replay'] ?? false),
            ],
            'error' => $this->summarizeError($envelope->error),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $error
     * @return array<string, mixed>|null
     */
    private function summarizeError(?array $error): ?array
    {
        if ($error === null) {
            return null;
        }

        $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
        $attempt = isset($error['attempt']) && is_int($error['attempt']) ? $error['attempt'] : null;
        $maxAttempts = isset($error['maxAttempts']) && is_int($error['maxAttempts']) ? $error['maxAttempts'] : null;
        $retryAt = isset($error['retryAt']) && is_string($error['retryAt']) ? $error['retryAt'] : null;

        return [
            'code' => is_string($code) ? $code : null,
            'attempt' => is_int($attempt) ? $attempt : null,
            'max_attempts' => is_int($maxAttempts) ? $maxAttempts : null,
            'retry_at' => is_string($retryAt) ? $retryAt : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private function summarizeKeys(array $meta): array
    {
        if ($meta === []) {
            return [];
        }

        return array_slice(array_keys($meta), 0, 15);
    }
}
