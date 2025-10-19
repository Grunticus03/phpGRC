# Integration Bus Queue Runbook (Draft)

The Integration Bus relies on Laravel queues backed by Redis. This runbook captures the worker
topology, retry/dead-letter strategy, and operational knobs needed once connectors are enabled.

## Queue Components

- `integration-bus` — primary queue consumed by Bus workers.
- `integration-bus:dlq` — dead-letter queue for exhausted jobs (mirrors envelope payload).
- `integration-bus:metrics` — optional stream for Prometheus/StatsD forwarding.
- `integration-bus:monitor` — Redis key tracking queue depth and lag.
- Jobs are handled by `App\Jobs\IntegrationBus\ProcessIntegrationBusMessage`, which forwards to the per-kind dispatcher/events under `App\Events\IntegrationBus`.

## Environment / Config

Set the following in `.env` or your process manager:

```
QUEUE_CONNECTION=redis
INTEGRATION_BUS_QUEUE=integration-bus
INTEGRATION_BUS_DLQ=integration-bus:dlq
INTEGRATION_BUS_MAX_ATTEMPTS=5
INTEGRATION_BUS_RETRY_SECONDS=120
INTEGRATION_BUS_BACKOFF_EXP=2
INTEGRATION_BUS_VISIBILITY_TIMEOUT=900
```

`config/queue.php` must read these values (custom config patch queued for Phase 6 implementation).

## Worker Pools

| Pool | Concurrency | Tags | Notes |
| --- | --- | --- | --- |
| `bus-general` | 6 | `kind:asset.discovery`, `kind:vendor.profile`, `kind:indicator.metric` | Default pool for Idempotent jobs. |
| `bus-incident` | 3 | `kind:incident.event` | Smaller pool; ensures incident updates stay ordered. |
| `bus-auth` | 2 | `kind:auth.provider` | Low-throughput health pings. |
| `bus-cyber` | 4 | `kind:cyber.metric` | Handles heavier metrics fan-out. |

Scale by adding additional Supervisor processes per pool. Start with 15 total workers for initial
launch; revisit after profiling real workloads.

### Supervisor Example

```
[program:phpgrc-bus-general]
command=php /var/www/api/artisan queue:work redis --queue=integration-bus --timeout=120 --sleep=3 --tries=%(ENV_INTEGRATION_BUS_MAX_ATTEMPTS)s --backoff=%(ENV_INTEGRATION_BUS_RETRY_SECONDS)s --name=bus-general
numprocs=6
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=360

[program:phpgrc-bus-incident]
command=php /var/www/api/artisan queue:work redis --queue=integration-bus --timeout=180 --sleep=3 --tries=%(ENV_INTEGRATION_BUS_MAX_ATTEMPTS)s --backoff=%(ENV_INTEGRATION_BUS_RETRY_SECONDS)s --name=bus-incident --queue=integration-bus
numprocs=3
```

Repeat similar blocks for `bus-auth` and `bus-cyber`. Ensure Supervisor is managed as a system
service and restarts on deployment.

## Retry & Backoff Policy

- `INTEGRATION_BUS_MAX_ATTEMPTS=5` to cap job retries.
- Exponential backoff: attempt `n` waits `INTEGRATION_BUS_RETRY_SECONDS * (INTEGRATION_BUS_BACKOFF_EXP^(n-1))`.
- Jobs exceeding `INTEGRATION_BUS_VISIBILITY_TIMEOUT` are considered lost; workers must be tuned to
  finish within 15 minutes or chunk long work into smaller jobs.
- Exhausted jobs auto-route to `integration-bus:dlq` for manual inspection.

### DLQ Handling

Inspect dead letters via:

```
php artisan queue:failed --queue=integration-bus
php artisan queue:retry --id=<failed_job_id>
php artisan queue:forget --id=<failed_job_id>
```

Store representative failed envelopes for testing; ensure secrets are redacted before sharing.

## Metrics & Alerts

- Track queue depth (`integration-bus:monitor`), job latency, and failure counts via Laravel Horizon
  or custom Redis polling.
- Emit Prometheus counters: `phpgrc_integration_bus_jobs_processed_total`, `..._failed_total`,
  `..._duration_seconds`.
- Alert when:
  - Depth > 10k for more than 5 minutes.
  - Failure rate > 2% over 15-minute window.
  - DLQ size > 100.

## Observability Pipeline

- Every processed envelope emits an `integration.bus.message.received` audit under the `INTEGRATION_BUS`
  category. Metadata includes connector key, run ID, payload key summary, provenance snapshot, and
  retry/error hints for DLQ investigations.
- Connector telemetry is persisted in `integration_connectors.meta.observability` with:
  - `total_received`, `status_counts.{processed,errored}`, and per-kind counters.
  - Last envelope identifiers: `last_envelope_id`, `last_kind`, `last_event`, `last_run_id`,
    `last_priority`, `last_status`.
  - Source snapshot: `last_source.{source,external_id,ingested_at,correlation_id,replay}` plus
    attachment summary and the most recent `meta` keys supplied by connectors.
  - `last_received_at` doubles as a heartbeat for the admin UI; it updates alongside
    `integration_connectors.last_health_at`.
- Audit/telemetry writes honor the `core.audit.enabled` toggle and fall back gracefully when the
  audit table or connectors table is unavailable (e.g., during migrations).

## Operational Checklist

1. Apply environment values and reload queue workers.
2. Verify workers register with expected names using `php artisan queue:monitor`.
3. Push sample jobs (one per `kind`) and confirm throughput and DLQ behavior.
4. Document custom scaling decisions in `docs/ops/INTEGRATION-BUS-QUEUE.md`.
5. Review metrics dashboards weekly; adjust concurrency/backoff as workloads grow.

## References

- Contract: `docs/integrations/INTEGRATION-BUS-CONTRACT.md`
- Developer guide: `docs/integrations/INTEGRATION-BUS-DEVELOPER-GUIDE.md`
- Phase 6 Kickoff: `docs/phase-6/PHASE-6-KICKOFF.md`
