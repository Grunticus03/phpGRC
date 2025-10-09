
# FILE: docs/OPS.md
# phpGRC Ops Runbook

## Metrics dashboard (Phase-5)

### Purpose
Expose authentication activity, evidence MIME distribution, and admin activity KPIs to the web UI.

### Defaults (env → config)
Set in your process manager or `.env`:

```
CORE_METRICS_RBAC_DENIES_WINDOW_DAYS=7
```

The API reads this into `config('core.metrics.rbac_denies.window_days')`.

User-supplied query params are clamped to `7..365`. Non-numeric falls back to defaults.

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
  Expect `data.auth_activity.window_days == 7` and totals reflecting recent authentication traffic.

- Clamping:
  ```
  curl -sS 'http://<host>/api/dashboard/kpis?auth_days=0' | jq '.auth_activity.window_days'
  # -> 7

  curl -sS 'http://<host>/api/dashboard/kpis?auth_days=999' | jq '.auth_activity.window_days'
  # -> 365
  ```

- Non-numeric values fall back to defaults:
  ```
  curl -sS 'http://<host>/api/dashboard/kpis?auth_days=foo' | jq '.auth_activity.window_days'
  # -> 7
  ```

- Admin report export:
  ```
  curl -sS -D - 'http://<host>/api/reports/admin-activity?format=csv' -o admin-activity.csv
  # Expect HTTP 200, Content-Type: text/csv; charset=UTF-8, and Content-Disposition filename.
  ```

### Verify Web
1. Open Dashboard.
2. Confirm stacked bar chart (“Authentications last N days”) renders with two series (Success/Failed).
3. Click a bar to ensure navigation to `/admin/audit` with date-scoped filters.
4. Confirm pie chart (“Evidence MIME types”) renders; clicking a slice navigates to `/admin/evidence?mime=<type>`.
5. Verify “Admin Activity” table lists admin users and shows last successful login timestamps or an em dash when absent.
6. Click “Download CSV” in the Admin Activity card to confirm the CSV downloads (requires `core.reports.view`).

### Troubleshooting
- **403**: user lacks metrics read permission. Grant the role or policy and retry.
- **403 on report download**: ensure the account holds `core.reports.view` (Admin by default). PAT-based sessions must include the bearer token in local storage.
- **200 but zeros**: no audit emits yet. Generate traffic that triggers RBAC denies or ingest Evidence.
- **Client percent off**: API may return `rate` in `0..1` while the UI expects `0..1` for rate and `0..100` for percent fields. The client normalizes internally; no server change needed.

### Notes
- One audit emit per denied request is preserved.
- KPI endpoints are read-only. No OpenAPI schema change; only description notes document defaults.

