<?php

declare(strict_types=1);

namespace App\Integrations\Bus;

use InvalidArgumentException;

/**
 * Value object representing a single Integration Bus envelope.
 *
 * Validation here is intentionally strict so that queue jobs can rely on a
 * consistent shape even before JSON Schema validation is hooked in.
 *
 * @psalm-type Attachment = array{
 *     type:string,
 *     uri?:string,
 *     contentType?:string,
 *     size?:int,
 *     hash?:string
 * }
 * @psalm-type Provenance = array{
 *     source:string,
 *     externalId:string,
 *     ingestedAt:string,
 *     schemaRef:string,
 *     sourceRegion?:string,
 *     sourceAccount?:string,
 *     correlationId?:string,
 *     checksum?:string,
 *     replay?:bool
 * }
 * @psalm-type EnvelopeArray = array{
 *     id:string,
 *     busVersion:string,
 *     connectorKey:string,
 *     connectorVersion:string,
 *     tenantId:string,
 *     runId:string,
 *     kind:string,
 *     event:string,
 *     emittedAt:string,
 *     receivedAt?:string,
 *     priority?:string,
 *     payload:array<string,mixed>,
 *     provenance:Provenance,
 *     attachments?:list<Attachment>,
 *     meta?:array<string,mixed>,
 *     error?:array<string,mixed>|null
 * }
 *
 * @phpstan-type Attachment = array{
 *     type:string,
 *     uri?:string,
 *     contentType?:string,
 *     size?:int,
 *     hash?:string
 * }
 * @phpstan-type Provenance = array{
 *     source:string,
 *     externalId:string,
 *     ingestedAt:string,
 *     schemaRef:string,
 *     sourceRegion?:string,
 *     sourceAccount?:string,
 *     correlationId?:string,
 *     checksum?:string,
 *     replay?:bool
 * }
 * @phpstan-type EnvelopeArray = array{
 *     id:string,
 *     busVersion:string,
 *     connectorKey:string,
 *     connectorVersion:string,
 *     tenantId:string,
 *     runId:string,
 *     kind:string,
 *     event:string,
 *     emittedAt:string,
 *     receivedAt?:string,
 *     priority?:string,
 *     payload:array<string,mixed>,
 *     provenance:Provenance,
 *     attachments?:list<Attachment>,
 *     meta?:array<string,mixed>,
 *     error?:array<string,mixed>|null
 * }
 */
final class IntegrationBusEnvelope
{
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    /**
     * @param  array<string,mixed>  $payload
     * @param  Provenance  $provenance
     * @param  list<Attachment>  $attachments
     * @param  array<string,mixed>  $meta
     * @param  array<string,mixed>|null  $error
     */
    private function __construct(
        public readonly string $id,
        public readonly string $busVersion,
        public readonly string $connectorKey,
        public readonly string $connectorVersion,
        public readonly string $tenantId,
        public readonly string $runId,
        public readonly string $kind,
        public readonly string $event,
        public readonly string $emittedAt,
        public readonly ?string $receivedAt,
        public readonly string $priority,
        public readonly array $payload,
        public readonly array $provenance,
        public readonly array $attachments,
        public readonly array $meta,
        public readonly ?array $error,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $required = [
            'id',
            'busVersion',
            'connectorKey',
            'connectorVersion',
            'tenantId',
            'runId',
            'kind',
            'event',
            'emittedAt',
            'payload',
            'provenance',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $input)) {
                throw new InvalidArgumentException("Integration Bus envelope missing required field [{$key}]");
            }
        }

        foreach (['id', 'busVersion', 'connectorKey', 'connectorVersion', 'tenantId', 'runId', 'kind', 'event', 'emittedAt'] as $field) {
            /** @var mixed $value */
            $value = $input[$field];
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("Integration Bus envelope field [{$field}] must be a non-empty string");
            }
        }
        /** @var string $id */
        $id = $input['id'];
        /** @var string $busVersion */
        $busVersion = $input['busVersion'];
        /** @var string $connectorKey */
        $connectorKey = $input['connectorKey'];
        /** @var string $connectorVersion */
        $connectorVersion = $input['connectorVersion'];
        /** @var string $tenantId */
        $tenantId = $input['tenantId'];
        /** @var string $runId */
        $runId = $input['runId'];
        /** @var string $kind */
        $kind = $input['kind'];
        /** @var string $event */
        $event = $input['event'];
        /** @var string $emittedAt */
        $emittedAt = $input['emittedAt'];

        $receivedAt = null;
        if (array_key_exists('receivedAt', $input)) {
            $receivedRaw = $input['receivedAt'];
            if (! is_string($receivedRaw)) {
                throw new InvalidArgumentException('Integration Bus envelope field [receivedAt] must be a string when present');
            }
            $receivedAt = $receivedRaw !== '' ? $receivedRaw : null;
        }

        /** @var mixed $payloadRaw */
        $payloadRaw = $input['payload'];
        if (! is_array($payloadRaw)) {
            throw new InvalidArgumentException('Integration Bus envelope field [payload] must be an array');
        }
        /** @var array<string,mixed> $payload */
        $payload = $payloadRaw;

        /** @var mixed $provenanceRaw */
        $provenanceRaw = $input['provenance'];
        if (! is_array($provenanceRaw)) {
            throw new InvalidArgumentException('Integration Bus envelope field [provenance] must be an array');
        }

        $requiredProvenance = ['source', 'externalId', 'ingestedAt', 'schemaRef'];
        foreach ($requiredProvenance as $field) {
            if (! array_key_exists($field, $provenanceRaw)) {
                throw new InvalidArgumentException("Integration Bus provenance missing required field [{$field}]");
            }
            /** @var mixed $value */
            $value = $provenanceRaw[$field];
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("Integration Bus provenance field [{$field}] must be a non-empty string");
            }
        }

        /** @var Provenance $provenance */
        $provenance = $provenanceRaw;

        $attachments = [];
        if (array_key_exists('attachments', $input)) {
            /** @var mixed $attachmentsRaw */
            $attachmentsRaw = $input['attachments'];
            if (! is_array($attachmentsRaw)) {
                throw new InvalidArgumentException('Integration Bus envelope field [attachments] must be an array when present');
            }
            $attachments = [];
            foreach ($attachmentsRaw as $attachmentRaw) {
                if (! is_array($attachmentRaw)) {
                    throw new InvalidArgumentException('Integration Bus attachment entries must be arrays');
                }
                if (! array_key_exists('type', $attachmentRaw) || ! is_string($attachmentRaw['type']) || $attachmentRaw['type'] === '') {
                    throw new InvalidArgumentException('Integration Bus attachment requires non-empty string [type]');
                }
                if (array_key_exists('uri', $attachmentRaw) && ! is_string($attachmentRaw['uri'])) {
                    throw new InvalidArgumentException('Integration Bus attachment [uri] must be a string when present');
                }
                if (array_key_exists('contentType', $attachmentRaw) && ! is_string($attachmentRaw['contentType'])) {
                    throw new InvalidArgumentException('Integration Bus attachment [contentType] must be a string when present');
                }
                if (array_key_exists('size', $attachmentRaw) && ! is_int($attachmentRaw['size'])) {
                    throw new InvalidArgumentException('Integration Bus attachment [size] must be an integer when present');
                }
                if (array_key_exists('hash', $attachmentRaw) && ! is_string($attachmentRaw['hash'])) {
                    throw new InvalidArgumentException('Integration Bus attachment [hash] must be a string when present');
                }

                /** @var Attachment $attachment */
                $attachment = $attachmentRaw;
                $attachments[] = $attachment;
            }
        }

        $meta = [];
        if (array_key_exists('meta', $input)) {
            /** @var mixed $metaRaw */
            $metaRaw = $input['meta'];
            if (! is_array($metaRaw)) {
                throw new InvalidArgumentException('Integration Bus envelope field [meta] must be an array when present');
            }
            /** @var array<string,mixed> $meta */
            $meta = $metaRaw;
        }

        $error = null;
        if (array_key_exists('error', $input)) {
            $errorRaw = $input['error'];
            if ($errorRaw !== null && ! is_array($errorRaw)) {
                throw new InvalidArgumentException('Integration Bus envelope field [error] must be array or null');
            }
            /** @var array<string,mixed>|null $error */
            $error = $errorRaw;
        }

        $priority = self::PRIORITY_NORMAL;
        if (array_key_exists('priority', $input)) {
            $priorityRaw = $input['priority'];
            if (! is_string($priorityRaw) || $priorityRaw === '') {
                throw new InvalidArgumentException('Integration Bus envelope field [priority] must be a non-empty string when present');
            }
            $priority = match ($priorityRaw) {
                self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH => $priorityRaw,
                default => throw new InvalidArgumentException("Integration Bus envelope contains invalid priority [{$priorityRaw}]"),
            };
        }

        return new self(
            id: $id,
            busVersion: $busVersion,
            connectorKey: $connectorKey,
            connectorVersion: $connectorVersion,
            tenantId: $tenantId,
            runId: $runId,
            kind: $kind,
            event: $event,
            emittedAt: $emittedAt,
            receivedAt: $receivedAt,
            priority: $priority,
            payload: $payload,
            provenance: $provenance,
            attachments: $attachments,
            meta: $meta,
            error: $error,
        );
    }

    /**
     * @return EnvelopeArray
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'busVersion' => $this->busVersion,
            'connectorKey' => $this->connectorKey,
            'connectorVersion' => $this->connectorVersion,
            'tenantId' => $this->tenantId,
            'runId' => $this->runId,
            'kind' => $this->kind,
            'event' => $this->event,
            'emittedAt' => $this->emittedAt,
            'payload' => $this->payload,
            'provenance' => $this->provenance,
        ];

        if ($this->receivedAt !== null) {
            $data['receivedAt'] = $this->receivedAt;
        }

        if ($this->priority !== self::PRIORITY_NORMAL) {
            $data['priority'] = $this->priority;
        }

        if ($this->attachments !== []) {
            $data['attachments'] = $this->attachments;
        }

        if ($this->meta !== []) {
            $data['meta'] = $this->meta;
        }

        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        return $data;
    }
}
