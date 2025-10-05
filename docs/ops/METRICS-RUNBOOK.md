# phpGRC Ops Runbook — Metrics & Dashboard

## Scope
Enable and operate the Phase-5 metrics KPIs: RBAC denies rate and Evidence freshness. Admin-only endpoints; no OpenAPI surface change in 0.4.7. :contentReference[oaicite:2]{index=2}

## Endpoints
- Internal KPIs: `GET /api/dashboard/kpis`
- Alias: `GET /api/metrics/dashboard` (same payload shape)
- RBAC: requires role **Admin** and policy `core.metrics.view`. Denies are audited. :contentReference[oaicite:3]{index=3}

## Payload shape (contract)
```json
{
  "ok": true,
  "data": {
    "rbac_denies": {
      "window_days": 7,
      "from": "YYYY-MM-DD",
      "to": "YYYY-MM-DD",
      "denies": 0,
      "total": 0,
      "rate": 0.0,
      "daily": [
        {"date": "YYYY-MM-DD", "denies": 0, "total": 0, "rate": 0.0}
      ]
    },
    "evidence_freshness": {
      "days": 30,
      "total": 0,
      "stale": 0,
      "percent": 0.0,
      "by_mime": [
        {"mime": "application/pdf", "total": 0, "stale": 0, "percent": 0.0}
      ]
    }
  },
  "meta": {
    "generated_at": "ISO-8601",
    "window": {"rbac_days": 7, "fresh_days": 30}
  }
}
```
This is the authoritative contract for Phase-5 KPIs. :contentReference[oaicite:4]{index=4}

## Defaults and tuning
Metrics code reads **config**, not env, at runtime. Set env, then map to config in `config/core.php`. :contentReference[oaicite:5]{index=5} :contentReference[oaicite:6]{index=6}

Recommended defaults:
- Evidence freshness days: `30` → `core.metrics.evidence_freshness.days`
- RBAC denies window days: `7` → `core.metrics.rbac_denies.window_days`
These values are the documented fallbacks for KPIs. :contentReference[oaicite:7]{index=7}

### Env keys (mapped at bootstrap)
Add to `.env` or server secrets, then ensure `config/core.php` maps them into `core.metrics.*`:
- `CORE_METRICS_EVIDENCE_FRESHNESS_DAYS=30`
- `CORE_METRICS_RBAC_DENIES_WINDOW_DAYS=7`

> Note: Do not call `env()` in code paths. Use `config('core.metrics.*')`. :contentReference[oaicite:8]{index=8}

## Security and RBAC
- Access restricted to Admin and policy `core.metrics.view`. Keep deny-by-default. :contentReference[oaicite:9]{index=9}
- RBAC deny events must emit **one audit per denied request** with non-empty `action`, `category`, and `entity_id`. Preserve that invariant. :contentReference[oaicite:10]{index=10}

## Web UI
- Dashboard consumes `/api/dashboard/kpis` (or `/api/metrics/dashboard`) and renders:
  - KPI tiles for Evidence freshness and RBAC denies rate
  - Per-MIME table for Evidence freshness
- Phase-5 acceptance notes call for Admin-only UI and custom 403 on deny. :contentReference[oaicite:11]{index=11}

## Operations
### Enable metrics
1) Set env keys above.
2) Deploy with config cache refresh: `php artisan config:clear && php artisan config:cache`
3) Verify RBAC policy grants Admins `core.metrics.view`.

### Smoke tests
- Auth as Admin, then:
  - `curl -H "Authorization: Bearer <token>" https://<host>/api/dashboard/kpis`
  - Expect `ok:true`, `data.rbac_denies.window_days=7` and `data.evidence_freshness.days=30` by default. :contentReference[oaicite:12]{index=12}

### Performance target
- Each KPI call ≤ 200 ms on ~10k `audit_events`. Investigate slow queries or missing indexes if exceeded. :contentReference[oaicite:13]{index=13}

## Notes
- Phase-5 guardrail: **no breaking OpenAPI changes** in 0.4.7; metrics remain internal. :contentReference[oaicite:14]{index=14} :contentReference[oaicite:15]{index=15}
- Configuration overlays may be supplied via the shared config file used in deployments; confirm overlay precedence during rollout. :contentReference[oaicite:16]{index=16}
