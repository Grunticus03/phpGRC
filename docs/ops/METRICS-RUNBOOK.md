# phpGRC Ops Runbook — Metrics & Dashboard

## Scope
Enable and operate the Phase-5 metrics KPIs: Authentication activity, Evidence MIME distribution, and Admin activity. Admin-only endpoints; no OpenAPI surface change in 0.4.7. :contentReference[oaicite:2]{index=2}

## Endpoints
- Internal KPIs: `GET /api/dashboard/kpis`
- Alias: `GET /api/metrics/dashboard` (same payload shape)
- RBAC: requires role **Admin** and policy `core.metrics.view`. Denies are audited. :contentReference[oaicite:3]{index=3}

## Payload shape (contract)
```json
{
  "ok": true,
  "data": {
    "auth_activity": {
      "window_days": 7,
      "from": "YYYY-MM-DD",
      "to": "YYYY-MM-DD",
      "daily": [
        {"date": "YYYY-MM-DD", "success": 0, "failed": 0, "total": 0}
      ],
      "totals": {"success": 0, "failed": 0, "total": 0},
      "max_daily_total": 0
    },
    "evidence_mime": {
      "total": 0,
      "by_mime": [
        {"mime": "application/pdf", "count": 0, "percent": 0.0}
      ]
    },
    "admin_activity": {
      "admins": [
        {"id": 1, "name": "Admin User", "email": "admin@example.test", "last_login_at": "YYYY-MM-DDTHH:MM:SSZ"}
      ]
    }
  },
  "meta": {
    "generated_at": "ISO-8601",
    "window": {"auth_days": 7, "rbac_days": 7}
  }
}
```
This is the authoritative contract for Phase-5 KPIs. :contentReference[oaicite:4]{index=4}

## Defaults and tuning
Metrics code reads **config**, not env, at runtime. Set env, then map to config in `config/core.php`. :contentReference[oaicite:5]{index=5} :contentReference[oaicite:6]{index=6}

Recommended default:
- Authentication window days: `7` → `core.metrics.rbac_denies.window_days`
This value is the documented fallback for the dashboard chart. :contentReference[oaicite:7]{index=7}

### Env keys (mapped at bootstrap)
Add to `.env` or server secrets, then ensure `config/core.php` maps them into `core.metrics.*`:
- `CORE_METRICS_RBAC_DENIES_WINDOW_DAYS=7`

> Note: Do not call `env()` in code paths. Use `config('core.metrics.*')`. :contentReference[oaicite:8]{index=8}

## Security and RBAC
- Access restricted to Admin and policy `core.metrics.view`. Keep deny-by-default. :contentReference[oaicite:9]{index=9}
- RBAC deny events must emit **one audit per denied request** with non-empty `action`, `category`, and `entity_id`. Preserve that invariant. :contentReference[oaicite:10]{index=10}

## Web UI
- Dashboard consumes `/api/dashboard/kpis` (or `/api/metrics/dashboard`) and renders:
  - Stacked bar chart for authentication successes vs failures (click-through to audit)
  - Pie chart summarizing evidence MIME counts (click-through to evidence filtered view)
  - Admin activity table listing admins and last successful login timestamps
- Phase-5 acceptance notes call for Admin-only UI and custom 403 on deny. :contentReference[oaicite:11]{index=11}

## Operations
### Enable metrics
1) Set env keys above.
2) Deploy with config cache refresh: `php artisan config:clear && php artisan config:cache`
3) Verify RBAC policy grants Admins `core.metrics.view`.

### Smoke tests
- Auth as Admin, then:
- `curl -H "Authorization: Bearer <token>" https://<host>/api/dashboard/kpis`
- Expect `ok:true`, `data.auth_activity.window_days=7`, and chart totals reflecting recent authentication traffic. :contentReference[oaicite:12]{index=12}

### Performance target
- Each KPI call ≤ 200 ms on ~10k `audit_events`. Investigate slow queries or missing indexes if exceeded. :contentReference[oaicite:13]{index=13}

## Notes
- Phase-5 guardrail: **no breaking OpenAPI changes** in 0.4.7; metrics remain internal. :contentReference[oaicite:14]{index=14} :contentReference[oaicite:15]{index=15}
- Configuration overlays may be supplied via the shared config file used in deployments; confirm overlay precedence during rollout. :contentReference[oaicite:16]{index=16}
