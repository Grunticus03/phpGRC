# Audit Retention Runbook

## Scope
Operate and verify audit log retention for phpGRC.

## Controls
- Config key: `core.audit.retention_days` (UI/Settings). Valid 1..730.
- Runtime clamp: purge command enforces [30, 730] for safety.
- Feature switch: `core.audit.enabled` (boolean).

## Schedule
- Daily purge at **03:10 UTC** when `core.audit.enabled = true`. Registered in the app scheduler.

## How it works
- Deletes events older than `now() - retention_days`.
- Processes in chunks to avoid long transactions.
- Optional summary emission may be enabled by configuration.

## Operate

### Check current setting
- Admin UI → Settings → Audit.
- Or environment override `CORE_AUDIT_RETENTION_DAYS`.

### Manual run
- App container/shell:  
  `php artisan audit:purge`
- Common options (if implemented):
  - `--dry-run` → report candidate count only.
  - `--days=NNN` → override for this run.
  - `--chunks=1000` → tune delete batch size.
  - `--emit-summary` → write a summary event.

### Verify purge
- API: `GET /api/audit?category=AUDIT&action=...` for a summary signal if enabled.
- DB: check `audit_events` oldest `occurred_at` ≥ cutoff.
- Logs: application log contains purge start/finish messages.
- CI: `AuditRetentionTest` covers cutoff and idempotency.

### Cutoff math
- `cutoff = now() - retention_days`.
- Any record with `occurred_at < cutoff` is a candidate.
- Clamp ensures cutoff never drops below 30 days from now even if configured lower.

## Troubleshooting
- **No space reclaimed:** check vacuum on SQLite or engine-specific GC.
- **Slow purge:** reduce `--chunks`, run during low traffic.
- **Permission errors:** ensure DB user has DELETE rights.
- **Feature disabled:** `core.audit.enabled` must be true.

## Change management
- Increase `retention_days` cautiously. Never reduce below compliance minimums.
- Document changes in release notes and infra change log.

## KPIs
- Purge duration.
- Rows deleted.
- Oldest event age after purge.

