
# FILE: docs/OPS.md
# phpGRC Ops Runbook

## Metrics dashboard (Phase-5)

### Purpose
Expose RBAC deny rate and Evidence freshness KPIs to the web UI.

### Defaults (env → config)
Set in your process manager or `.env`:

```
CORE_METRICS_EVIDENCE_FRESHNESS_DAYS=30
CORE_METRICS_RBAC_DENIES_WINDOW_DAYS=7
```

The API reads these into:

- `config('core.metrics.evidence_freshness.days')`
- `config('core.metrics.rbac_denies.window_days')`

User-supplied query params are clamped to `1..365`. Non-numeric falls back to defaults.

### RBAC
Viewing KPIs requires a policy that grants metrics read (e.g., `core.metrics.view`). If the check fails the API returns `403` and the UI shows “You do not have access to KPIs.”

### Deploy
1. Apply env values above.
2. Reload the app so config is re-read.
   - Laravel example:
     ```
     php artisan config:clear
     php artisan cache:clear
     ```
3. Confirm app is healthy: `GET /api/health` → `{ "ok": true }`.

### Verify API
Use either envelope or raw shape; both are accepted by the web client.

- Defaults applied:
  ```
  curl -sS 'http://<host>/api/dashboard/kpis' | jq .
  ```
  Expect:
  - `evidence_freshness.days == 30`
  - `rbac_denies.window_days == 7`

- Clamping:
  ```
  curl -sS 'http://<host>/api/dashboard/kpis?days=0&rbac_days=0' | jq '.evidence_freshness.days, .rbac_denies.window_days'
  # -> 1, 1

  curl -sS 'http://<host>/api/dashboard/kpis?days=999&rbac_days=999' | jq '.evidence_freshness.days, .rbac_denies.window_days'
  # -> 365, 365
  ```

- Negative values and junk are ignored (defaults used):
  ```
  curl -sS 'http://<host>/api/dashboard/kpis?days=foo&rbac_days=bar' | jq '.evidence_freshness.days, .rbac_denies.window_days'
  # -> 30, 7
  ```

### Verify Web
1. Open Dashboard.
2. Adjust “RBAC window (days)” and “Evidence stale threshold (days)”.
3. Click **Apply**. The client calls:
   ```
   GET /api/dashboard/kpis?rbac_days=<n>&days=<m>
   ```
   The KPI cards update. The denies card shows a sparkline of daily denies.

### Troubleshooting
- **403**: user lacks metrics read permission. Grant the role or policy and retry.
- **200 but zeros**: no audit emits yet. Generate traffic that triggers RBAC denies or ingest Evidence.
- **Client percent off**: API may return `rate` in `0..1` while the UI expects `0..1` for rate and `0..100` for percent fields. The client normalizes internally; no server change needed.

### Notes
- One audit emit per denied request is preserved.
- KPI endpoints are read-only. No OpenAPI schema change; only description notes document defaults.

