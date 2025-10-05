# @phpgrc:/docs/phase-5/PHASE-5-RELEASE-NOTES.md
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
- **WebUI auth-gating (added)**
  - The SPA now detects `require_auth` via `GET /api/health/fingerprint` and, only when true, probes the session with `GET /api/auth/me`. Unauthenticated users are redirected to `/auth/login`; otherwise content loads without redirects.
  - Guard lives in `AppLayout` and runs on initial load and hard reloads. When `require_auth` is false, no auth checks or redirects are performed.
- **Admin Users Management (beta)**
  - API (admin-only):  
    - `GET /api/admin/users` — search & paginate users.  
    - `POST /api/admin/users` — create user (name, email, password) and optional role assignment.  
    - `PATCH /api/admin/users/{id}` — update name/email/password and roles.  
    - `DELETE /api/admin/users/{id}` — delete user.  
  - UI: `/admin` → **Users** lets admins list/create/edit/delete users and assign roles; supports idempotent role updates and normalization.
  - Audit: user CRUD and role changes emit RBAC and AUTH audit events (where enabled).
- **Global Navigation (added)**
  - Accessible nav bar with active-route highlighting and “Skip to content” link, rendered across the SPA (visible once bootstrap completes).

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
- **Rate limiting (standardized):**
  - New reusable middleware `GenericRateLimit` replaces per-feature throttles. Per-route defaults use `->defaults('throttle', {strategy, window_seconds, max_requests})`.
  - Global knobs live under `core.api.throttle.*`. **ENV (`CORE_API_THROTTLE_*`) has precedence** over DB and config.
  - Unified headers on **200** and **429** where throttles apply: `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`.
  - Unified 429 JSON envelope: `{ ok:false, code:'RATE_LIMITED', retry_after:<int> }`.
  - **Exclusions:** No public route throttles for `/health` and `/openapi.*`. Login remains guarded only by `BruteForceGuard` to avoid double throttling.
  - `/health/fingerprint` now includes `summary.api_throttle:{ enabled, strategy, window_seconds, max_requests }`.
- **Admin Users API (beta):**
  - Routes live under `/api/admin/users` (see **New** above). Enforced by RBAC with `Admin` role and `core.users.manage` policy; respects `require_auth` and global `rbac.enabled`.

## Config Defaults
- Evidence freshness days: **30** via config fallback.  
  - **Note:** ENV defaults for app behavior are deprecated; use DB. ENV remains for **connection/bootstrap only**.
- RBAC denies window days: **7** via config fallback.  
  - **Note:** same deprecation; DB overrides take precedence.
- **RBAC require_auth (clarification):** may be toggled by DB override; tests and non-auth SPA flows can run with RBAC disabled on test boxes.
- **ENV usage (clarification):** Other than DB connection/bootstrap, runtime behavior is sourced from DB-backed settings. `.env` keys for behavior are read only as legacy fallbacks when DB is not yet initialized.

## Compatibility
- No breaking OpenAPI changes.
- No migrations.  
  - **Superseded (added):** `2025_09_04_000001_create_core_settings_table.php` introduces the `core_settings` table for DB-backed settings.
- **Routes (added):** KPI alias route introduced; both endpoints return identical shapes.
- **Deprecations:**
  - `MetricsThrottle` middleware deprecated and not used by routes. Use `GenericRateLimit` instead.
  - Legacy `core.metrics.throttle.*` knobs deprecated; `core.metrics.throttle.enabled` is forced `false`.
  - **WebUI reliance on `.env` for feature flags is deprecated** (DB-backed flags are authoritative). The legacy code path remains to support bootstrap-before-DB with a warning in logs.

## Security
- Requires `core.metrics.view` policy (Admin).
- One audit row per deny outcome.
- **Settings persistence (added):** settings changes raise a `SettingsUpdated` domain event; audit payload enrichment is planned (see “Planned”).
- **Admin Users (beta):** endpoints require `Admin` + `core.users.manage`; inputs validated; password writes require explicit payload fields; role changes normalized; all actions audited where audit is enabled.

## WebUI
- Auth bootstrap respects `require_auth` and avoids spurious login redirects when disabled.
- Global nav now renders post-bootstrap; accessible “Skip to content”; active route highlighting.
- Admin **Users** screen added (beta): list/search/paginate; create/update/delete; assign/remove roles; inline validation and error surfaces; optimistic UI refresh on success.
- Login screen wired to API; logout clears session and returns to login when `require_auth` is true, or stays on current page when disabled.

## Docs
- Redoc x-logo path fixed: `x-logo.url: "/api/images/phpGRC-light-horizontal-trans.png"`.
- API docs UI is now served at **/api/docs** and linked from the Admin UI.
- **OpenAPI:** `components.responses.RateLimited` documents headers `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`. `HealthFingerprintResponse.summary.api_throttle` documented.
- **OpenAPI (pending, next):** `/api/admin/users*` endpoints will be added with full request/response schemas and examples in the next doc sweep (beta feature).

## Tests
- PHPUnit: all routes in tests updated to **drop** `/api` prefix (framework serves bare paths in test kernel).
- Vitest: fetch mocked with a **Response-like** object (`headers`, `json()`, `text()`) so KPI UI renders and labels appear.
- **Rate limit tests:** assert presence of `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining` on **200** and **429** for one user-route and one ip-route.
- WebUI: unit tests adjusted for auth bootstrap guard; KPI tests updated to assert query param application and forbidden messaging; new Users UI tests to be added post-OpenAPI doc finalization.

## Ops
- **Deployment:** Apache vhost serves SPA at `/`; `/api/` aliased to Laravel public + FPM. Ensure `route:clear` and `config:clear` after deploys.
- **Cache driver:** Prefer `file` cache unless DB cache table is migrated (`php artisan cache:table && php artisan migrate`).
- **OpenAPI docs caching:** reverse proxies must not strip `ETag`; keep `nosniff`; honor `Cache-Control: no-store, max-age=0`.
- **Secrets:** keep `APP_KEY` in environment/KMS, not DB. DB must hold non-connection settings only.
- **Rate limiting knobs for load tests:** disable globally via DB
  ```json
  { "core": { "api": { "throttle": { "enabled": false } } } }
  ```
  or set ENV defaults (`CORE_API_THROTTLE_ENABLED=false`) for ephemeral runs. Clear config cache after deploy.
- **Toggling `require_auth`:** after flipping in DB, `config:clear` and `route:clear` are recommended to ensure guards/middleware read current settings in long-lived workers.

## Fixed
- WebUI always-redirecting-to-login when `require_auth` was disabled — corrected by two-step bootstrap (read flag, then probe only when required).
- Navbar not rendering until after bootstrap — now renders consistently once the auth gating decision is made.
- Minor KPI UI label alignment and aria attributes for assistive tech.

## Known Issues / Follow-ups
- Admin Users (beta): fine-grained permission assignment UI is scoped to role assignment in this release; per-permission toggles are planned.
- OpenAPI coverage for `/api/admin/users*` pending; use in-app docs link for other endpoints until the next doc sweep.
- Optional KPI caching knobs are planned and not enabled in this release.

## CI
- **Required checks:** OpenAPI lint (Redocly), OpenAPI breaking-change gate (openapi-diff), API static, API tests (MySQL 8.3) + coverage, Web build + tests + audit.
- Redocly replaces Spectral in CI; Spectral usage optional for local checks.

## Planned (next iteration; not shipped in this release)
- Audit diffs/traceability: include `{key, old, new}` per change in `settings.update` events and surface in `/api/audit`.
- KPI caching: honor `core.metrics.cache_ttl_seconds` with `meta.cache:{ttl,hit}`.
- Optional: `/api/openapi.json` mirror with parity tests.
```
::contentReference[oaicite:0]{index=0}
