# Integration Bus Contract v1.0 (Draft)

The Integration Bus is the shared queue and event pipeline used by phpGRC connectors. Every
connector message **must** follow this contract so downstream modules (Assets, Vendors, Incidents,
Indicators, Cyber Metrics) can rely on a stable payload and provenance envelope.

This document is the canonical reference. The machine-readable schema lives at
`docs/integrations/integration-bus-envelope.schema.json`. Connector authors can find architecture
context and SDK snippets in `docs/integrations/INTEGRATION-BUS-DEVELOPER-GUIDE.md`.

## Scope

- Applies to all queue jobs published to the `integration-bus` queue (Laravel queue name) and
  related dead-letter queues.
- Governs connector-produced payloads that enter the platform (ingest) as well as internal bus
  events emitted for retries, health checks, or downstream fan-out.
- Out of scope for this draft: streaming transports, manual CSV imports, or legacy webhook bridges.

## Envelope Shape

Every Integration Bus job is a single JSON object with the following top-level fields.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | string | yes | ULID generated when the connector enqueues the job. |
| `busVersion` | string | yes | Semver indicating the contract version the connector targets. |
| `connectorKey` | string | yes | Lowercase slug (e.g. `aws-config`, `servicenow`), mapped in DB. |
| `connectorVersion` | string | yes | Semver of the connector bundle/source. |
| `tenantId` | string | yes | ULID of phpGRC tenant/instance; `core.default` for single-tenant. |
| `runId` | string | yes | ULID for the connector execution batch; reused for all jobs in a run. |
| `kind` | string | yes | High-level archetype. See [Connector Kinds](#connector-kinds). |
| `event` | string | yes | Specific action within the archetype (e.g. `asset.upserted`). |
| `emittedAt` | string (date-time) | yes | RFC 3339 timestamp when the connector created the job. |
| `receivedAt` | string (date-time) | no | RFC 3339 timestamp captured by bus ingestion (filled by core). |
| `priority` | string | no | `low` \| `normal` \| `high`; defaults to `normal`. |
| `payload` | object | yes | Business data in normalized shape per archetype. |
| `provenance` | object | yes | Source metadata used for lineage/audit (see below). |
| `attachments` | array<object> | no | Side-car references (S3, blob IDs). |
| `meta` | object | no | Additional connector hints; validated against schema. |
| `error` | object|null | no | Populated by bus on failure before DLQ (see [Error Contract](#error-contract)). |

Connectors MUST NOT include extra top-level keys; consumers SHOULD reject unknown properties during
validation.

### Provenance Object

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `source` | string | yes | Human-readable system name (`aws-config`, `okta`, `jira`). |
| `sourceRegion` | string | no | Cloud/geo partition (`us-east-1`, `emea`). |
| `sourceAccount` | string | no | Account/tenant identifier in upstream system. |
| `externalId` | string | yes | Stable ID from source system for the logical record. |
| `correlationId` | string | no | Trace correlation ID (UUID string; hyphenated hex). |
| `ingestedAt` | string (date-time) | yes | Timestamp the upstream data was observed. |
| `checksum` | string | no | Hex SHA-256 hash of serialized payload for idempotency. |
| `schemaRef` | string | yes | Absolute URL to the payload schema (e.g. Bus contract doc section). |
| `replay` | boolean | no | Indicates record is a re-emit/replay. Defaults to `false`. |

### Attachments Array

Attachments provide external binary/object references that should be fetched lazily.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `type` | string | yes | `evidence`, `log`, `artifact`, `report`. |
| `uri` | string | yes | `https://` or `s3://` style URI; signed when required. |
| `contentType` | string | yes | MIME type (e.g. `application/json`, `text/csv`). |
| `size` | integer | no | Size in bytes. |
| `hash` | string | no | Hex SHA-256 or SHA-512 digest of attachment contents. |

## Connector Kinds

The `kind` field determines the payload envelope. New kinds require Architecture sign-off and a
minor contract bump. Initial values:

- `asset.discovery` — Normalized asset inventory record.
- `asset.lifecycle` — Asset state change (e.g. decommissioned).
- `incident.event` — Incident updates created externally.
- `vendor.profile` — Vendor catalog load.
- `indicator.metric` — KPI/KRI metric observation.
- `cyber.metric` — Vulnerability/SIEM summary.
- `auth.provider` — External IdP health/status emission.

### Payload Requirements by Kind

#### `asset.discovery`

- `payload` MUST include `assetId`, `name`, `type`, `environment`, `tags`, and `attributes`.
- `attributes` is a dictionary of primitive values or arrays (string, number, boolean).
- `relationships`, when supplied, is an array of `{ "type": "depends_on", "target": "<externalId>" }`.
- `schemaRef` MUST point to `#/$defs/payloadAssetDiscovery` in the JSON Schema.

#### `incident.event`

- `payload` MUST include `incidentId`, `status`, `severity`, `summary`, and optional `details` and
  `links`.
- `status` uses the canonical state machine values (`NEW`, `TRIAGE`, `CONTAINED`, `ERADICATED`,
  `RECOVERED`, `CLOSED`).
- `links` is an array of `{ "type": "evidence", "target": "<externalId>" }`.

#### `indicator.metric`

- `payload` MUST include `indicatorKey`, `window`, `value`, `unit`, and `context`.
- `window` is an ISO 8601 duration (`P1D`, `P1M`).
- `context` holds supporting dimensions (module, capability, jurisdiction).

Other kinds follow the same pattern and are described inline in the JSON Schema.

## Queue Headers

Laravel queue metadata MUST include the headers below so that worker pools can route jobs without
deserializing payloads:

| Header | Required | Description |
| --- | --- | --- |
| `x-phpgrc-bus-version` | yes | Mirrors `busVersion`. |
| `x-phpgrc-connector` | yes | Mirrors `connectorKey`. |
| `x-phpgrc-kind` | yes | Mirrors `kind`. |
| `x-phpgrc-run-id` | yes | Mirrors `runId`. |
| `x-phpgrc-priority` | no | Mirrors `priority` (`low`, `normal`, `high`). |
| `x-phpgrc-correlation` | no | Mirrors `provenance.correlationId`. |

Workers MUST verify header values match the JSON body before processing.

## Error Contract

When a job fails but remains retriable, the bus MUST annotate the envelope with an `error` object.
Dead-letter moves MUST preserve this object for audits.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `code` | string | yes | Canonical machine code (`VALIDATION_FAILED`, `CONNECTOR_TIMEOUT`). |
| `message` | string | yes | Human-readable message (≤ 512 chars). |
| `occurredAt` | string (date-time) | yes | Timestamp of the failure. |
| `attempt` | integer | yes | Attempt number (starts at 1). |
| `maxAttempts` | integer | yes | Configured max attempts for the job. |
| `retryAt` | string (date-time) | no | Scheduled next attempt time. |
| `traceId` | string | no | Trace/span identifier. |

On successful processing, workers MUST clear any populated `error` object before acknowledging the
job.

## Versioning Rules

- Major version bumps (`2.x`) indicate breaking changes and require a new queue name.
- Minor version bumps (`1.x`) add optional fields or new enumerations; backwards compatible.
- Patch bumps (`1.0.x`) document clarifications without schema changes.
- Connectors MUST declare the minimum supported `busVersion` and fail fast if the bus advertises a
  higher incompatible version.

## Validation Expectations

- The envelope MUST validate against `integration-bus-envelope.schema.json`.
- PHPUnit integration tests MUST load fixtures that cover each `kind` and validate using the schema.
- Queue smoke tests MUST assert headers mirror body values.
- Psalm/PHPStan stubs SHOULD describe DTOs matching this contract for typed PHP usage.

## Security Requirements

- Secrets (API tokens, passwords) MUST NOT appear in the payload or provenance. Connectors use the
  encrypted configuration store for secrets.
- Attachments MUST be signed URLs or phpGRC blob IDs; never raw credentials.
- All timestamps MUST be normalized to UTC.
- Idempotency relies on `(connectorKey, externalId, kind)`; workers MUST dedupe based on these keys
  when replaying.

## Example Envelope

```json
{
  "id": "01JB1K83J3H9QF9P3T0Y3PG1YD",
  "busVersion": "1.0.0",
  "connectorKey": "aws-config",
  "connectorVersion": "2025.10.0",
  "tenantId": "01HZXA3AMW2J3C4G4Q6G2XDQZN",
  "runId": "01JB1K80QPJVDK2XYRX2G5V7WZ",
  "kind": "asset.discovery",
  "event": "asset.upserted",
  "emittedAt": "2026-01-12T12:15:32.511Z",
  "priority": "high",
  "payload": {
    "assetId": "i-0f123456789abcd",
    "name": "prod-app-1",
    "type": "ec2.instance",
    "environment": "production",
    "tags": ["app:phpgrc", "tier:web"],
    "attributes": {
      "account": "123456789012",
      "region": "us-east-1",
      "privateIp": "10.0.5.21"
    },
    "relationships": [
      {
        "type": "depends_on",
        "target": "sg-0f123456789abcd"
      }
    ]
  },
  "provenance": {
    "source": "aws-config",
    "sourceRegion": "us-east-1",
    "sourceAccount": "123456789012",
    "externalId": "arn:aws:ec2:us-east-1:123456789012:instance/i-0f123456789abcd",
    "correlationId": "b9d62e4e-8626-4b24-b65d-2f6fc65ff8f7",
    "ingestedAt": "2026-01-12T12:15:30.100Z",
    "checksum": "c950b47ef85ffe0321fd7808916b5083d042d415a9d31b90d0b60a5d5a206c80",
    "schemaRef": "https://phpgrc.internal/docs/integrations/integration-bus-envelope.schema.json#/$defs/payloadAssetDiscovery",
    "replay": false
  },
  "attachments": [
    {
      "type": "artifact",
      "uri": "s3://phpgrc-connectors/aws-config/01JB1K80QPJVDK2XYRX2G5V7WZ/asset.json",
      "contentType": "application/json",
      "hash": "0c0a68f1fa0d7ff706d9fd986aa6a0ebc4438e8b99f95ac8cde2a4e99a2af9c6"
    }
  ],
  "meta": {
    "collectionWindow": "PT5M",
    "retryHint": null
  },
  "error": null
}
```

## Approval Path

1. Architecture review (Integration Bus track lead + Security) validates schema, provenance, and
   dedupe rules.
2. Update `docs/phase-6/PHASE-6-KICKOFF.md` decision log with approval date and reviewers.
3. Publish announcement in engineering channel linking to this document and the JSON Schema.
4. Update Phase 6 checklist item `Confirm Integration Bus contract schema approved and published`
   once steps above are complete.
