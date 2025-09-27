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

## Routing & Deploy (history mode)
- SPA now uses BrowserRouter (history mode). Deep-link reloads serve `index.html` via Apache fallback.
- Apache vhost simplified:
  - SPA at `/var/www/phpgrc/current/web/dist` with fallback rewrite to `index.html`.
  - Laravel mounted at `/api/` using `Alias /api/ /var/www/phpgrc/current/api/public/`.
  - Long-cache for `/assets/*`; `index.html` served with `Cache-Control: no-store, max-age=0`.
- **Laravel API prefix:** internal routes have **no** `api` prefix. Apache provides the external `/api/*` path. Tests updated.

## API
- Internal admin-only endpoint: `GET /api/dashboard/kpis` (unchanged contract).
- Input hardening: clamps `days` and `rbac_days` to **[1,365]**.
- PolicyMap now audits unknown roles in overrides once per policy per boot (persist mode).
- **Alias (added):** `GET /api/metrics/dashboard` → same controller/shape as `/api/dashboard/kpis`.
- **Response meta (added):** KPI responses may include `meta.window: { rbac_days, fresh_days }` for UI display.
- **Settings load (added):** DB overrides are loaded at boot via `SettingsServiceProvider`; API returns effective config (defaults overlaid by DB).
- **OpenAPI serve (hardened):** exact `Content-Type` for YAML, `ETag: "sha256:<hex>"`, `Cache-Control: no-store, max-age=0`, `X-Content-Type-Options: nosniff`; optional `Last-Modified` when file exists.
- **Prefix clarification:** In `bootstrap/app.php` the API routing `prefix` is set to `''`. Route list shows bare paths like `health`, `dashboard/kpis`. Apache mounts these under `/api/*`.

## Config Defaults
- Evidence freshness days: **30** via config fallback.  
  - **Note:** ENV defaults for app behavior are deprecated; use DB. ENV remains for **connection/bootstrap only**.
- RBAC denies window days: **7** via config fallback.  
  - **Note:** same deprecation; DB overrides take precedence.
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

## Docs
- Redoc x-logo path fixed: `x-logo.url: "/api/images/phpGRC-light-horizontal-trans.png"`.
- API docs UI is now served at **/api/docs** and linked from the Admin UI.

## Tests
- PHPUnit: all routes in tests updated to **drop** `/api` prefix (framework serves bare paths in test kernel).
- Vitest: fetch mocked with a **Response-like** object (`headers`, `json()`, `text()`) so KPI UI renders and labels appear.

## Ops
- **Deployment:** Apache vhost serves SPA at `/`; `/api/` aliased to Laravel public + FPM. Ensure `route:clear` and `config:clear` after deploys.
- **Cache driver:** Prefer `file` cache unless DB cache table is migrated (`php artisan cache:table && php artisan migrate`).
- **OpenAPI docs caching:** reverse proxies must not strip `ETag`; keep `nosniff`; honor `Cache-Control: no-store, max-age=0`.
- **Secrets:** keep `APP_KEY` in environment/KMS, not DB. DB must hold non-connection settings only.

## CI
- **Required checks:** OpenAPI lint (Redocly), OpenAPI breaking-change gate (openapi-diff), API static, API tests (MySQL 8.3) + coverage, Web build + tests + audit.
- Redocly replaces Spectral in CI; Spectral usage optional for local checks.

## Planned (next iteration; not shipped in this release)
- Audit diffs/traceability: include `{key, old, new}` per change in `settings.update` events and surface in `/api/audit`.
- KPI caching: honor `core.metrics.cache_ttl_seconds` with `meta.cache:{ttl,hit}`.
- Optional: `/api/openapi.json` mirror with parity tests.
