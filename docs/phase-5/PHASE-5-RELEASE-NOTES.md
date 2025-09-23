# Release Notes — Phase 5

## New
- KPI Dashboard polish
  - Adjustable windows: RBAC window days and evidence stale threshold.
  - Sparkline for daily RBAC denies.
- RBAC Audit UX
  - Human-readable labels and ARIA text for `rbac.deny.*` in Audit UI.
- **DB-backed Settings (added)**
  - All non-connection core settings now persist in the `core_settings` table.
  - Admin UI `PUT /api/admin/settings` supports `apply: true` for persistence and stub mode when persistence is unavailable.
- **Metrics endpoint alias (added)**
  - `GET /api/metrics/dashboard` added as an alias to the KPI dashboard route for compatibility.

## API
- Internal admin-only endpoint: `GET /api/dashboard/kpis` (unchanged contract).
- Input hardening: clamps `days` and `rbac_days` to **[1,365]**.
- PolicyMap now audits unknown roles in overrides once per policy per boot (persist mode).
- **Alias (added):** `GET /api/metrics/dashboard` → same controller/shape as `/api/dashboard/kpis`.
- **Response meta (added):** KPI responses may include `meta.window: { rbac_days, fresh_days }` for UI display.
- **Settings load (added):** DB overrides are loaded at boot via `SettingsServiceProvider`; API returns effective config (defaults overlaid by DB).

## Config Defaults
- Evidence freshness days: **30** via `CORE_METRICS_EVIDENCE_FRESHNESS_DAYS`.  
  - **Out of scope now:** ENV defaults are deprecated for app settings; values should be stored in DB. ENV remains for connection/bootstrap only.
- RBAC denies window days: **7** via `CORE_METRICS_RBAC_DENIES_WINDOW_DAYS`.  
  - **Out of scope now:** same deprecation note as above; use DB overrides instead.
- **RBAC require_auth (clarification):** may be toggled by DB override; tests and non-auth SPA flows can run with RBAC disabled on test boxes.

## Compatibility
- No breaking OpenAPI changes.
- No migrations.  
  - **Superseded (added):** `2025_09_04_000001_create_core_settings_table.php` introduces the `core_settings` table for DB-backed settings.
- **Routes (added):** KPI alias route introduced; both endpoints return identical shapes.

## Security
- Requires `core.metrics.view` policy (Admin).
- One audit row per deny outcome.
- **Settings persistence (added):** settings changes raise a `SettingsUpdated` domain event; audit payload enrichment is planned (see “Planned”).

## Ops
- See `docs/OPS.md` for enabling metrics and defaults.
- **Deployment (added):** Recommended Apache layout: serve SPA at `/`, reverse-proxy `/api/` to the Laravel public front controller (or an internal vhost). Ensure `route:clear` and `config:clear` after deploys.
- **Cache driver (added):** Prefer `file` cache unless DB cache table is migrated (`php artisan cache:table && php artisan migrate`).

## Planned (next iteration; not shipped in this release)
- Audit diffs/traceability: include `{key, old, new}` per change in `settings.update` events and surface in `/api/audit`.
- KPI caching: honor `core.metrics.cache_ttl_seconds` with `meta.cache:{ttl,hit}`.

