# Phase 5 — Minimal PR Checklist

## Gates
- PHPStan level 9: ✅
- Psalm: ✅
- PHPUnit: ✅ all suites
- OpenAPI:
  - `openapi-diff docs/api/baseline/openapi-0.4.7.yaml docs/api/openapi.yaml` returns no breaking changes (unless approved)
  - **Redocly lint**: ✅
  - **Spec augmentation (additive)**: inject standard `401/403/422` responses where appropriate; JSON/YAML parity verified via tests. ✅
  - **Additive 429**: `components.responses.RateLimited` added and referenced on throttled endpoints; headers documented (`Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`). ✅
  - **Redoc compatibility**: top-level `security` guaranteed to be an array (e.g., `[]`) to avoid `.map` crash. ✅

**Required branch-protection checks (names)**
- `OpenAPI lint`
- `OpenAPI breaking-change gate`
- `API static`
- `API tests (MySQL, PHP 8.3) + coverage`
- `Web build + tests + coverage + audit`

## RBAC and Auth
- `core.rbac.policies` updates documented in RBAC notes.
- Middleware denies return 403 JSON when RBAC enabled; one audit per denied request.
- `/api/rbac/*` routes remain registered; access enforced via middleware.
- Auth brute-force settings wired:
  - `core.auth.bruteforce.enabled`
  - `strategy: ip|session` (default session)
  - `window_seconds`, `max_attempts` (default 900s, 5)
  - Session cookie drops on first attempt.
  - Lock emits configured status (default 429) with `Retry-After`; audited as `auth.bruteforce.locked`.
- **Clarification (actual actions emitted)**: `auth.login.failed` on attempt; `auth.login.locked` on lock. ✅
- **Generic API rate limiting (new this session)**: ✅
  - Middleware `App\Http\Middleware\GenericRateLimit` added (strategies: `user|ip|session`) with per-route defaults via `Route::defaults(['throttle'=>...])`.
  - Global knobs: `core.api.throttle.enabled|strategy|window_seconds|max_requests` with ENV mapping (`CORE_API_THROTTLE_*`). DB overrides supported.
  - `/auth/login` remains on `BruteForceGuard`; other routes can opt into GenericRateLimit.
  - Unified 429 JSON envelope via `Exceptions\Handler`: `{ ok:false, code:"RATE_LIMITED", retry_after:int }` + headers.
- **Web UI auth bootstrap (added)**: AppLayout reads `/api/health/fingerprint` → `require_auth`; probes `/api/auth/me` as needed; redirects to `/auth/login` only when required and unauthenticated. ✅

## Audit
- Emit `RBAC` denies with canonical actions:
  - `rbac.deny.capability`, `rbac.deny.unauthenticated`, `rbac.deny.role_mismatch`, `rbac.deny.policy`.
- Emit `AUTH` events: `auth.login.success|failed|logout|break_glass.*`, `auth.bruteforce.locked`.
- PolicyMap override safety: unknown roles in overrides audited as `rbac.policy.override.unknown_role` with `meta.unknown_roles`.
- `actor_id` rules:
  - `null` when unauthenticated or auth fails.
  - user id on success.
- UI label mapping for `rbac.deny.*` integrated via `web/src/lib/audit/actionInfo.ts` with tests. ✅
- **Rate-limit audits**: lock path recorded; tests updated to assert single lock + prior failed attempts. ✅

## Dashboards
- Implement 2 KPIs first: Evidence freshness, RBAC denies rate. ✅
- Internal endpoint `GET /api/dashboard/kpis` (Admin-only) without OpenAPI change. ✅
  - Note: Response may include optional `meta.window` with `{ rbac_days, fresh_days, tz?, granularity?, from?, to? }` when range params are provided. ✅
- Defaults read from config with safe fallbacks:
  - `core.metrics.evidence_freshness.days` → fallback 30
  - `core.metrics.rbac_denies.window_days` → fallback 7
  - **Out of scope now:** Non-connection settings are sourced from DB overrides; config values serve as bootstrap/test defaults only.
- Seed data fixtures for audit/evidence. ✅
- Feature tests cover 401/403 and data correctness. ✅
- Performance check documented. ✅
- Frontend: adjustable windows wired to query. ✅
- Frontend: sparkline for daily RBAC denies series rendered in the card. ✅
- **Alias route:** `GET /api/metrics/dashboard` returns identical shape to `/api/dashboard/kpis`. ✅
- **Note**: Legacy `MetricsThrottle` replaced on metrics routes by `GenericRateLimit`; metrics knob may be disabled. ✅

## Admin Users Management (beta) — API & Web UI (added)
- API routes under `/api/admin/users`:
  - `GET /admin/users?q=&page=&per_page=` (paged search; stable by `id`)
  - `POST /admin/users` (create: name, email, password?, roles[])
  - `PATCH /admin/users/{id}` (update: name?, email?, password?, roles[])
  - `DELETE /admin/users/{id}` (delete)
- Validation:
  - name: string ≥2
  - email: RFC email, unique
  - password: ≥8 (optional on update)
  - roles: list of normalized names/ids; duplicates after normalization rejected
- RBAC: requires Admin role + `core.users.manage` policy. Responses normalized to `{ ok, user|data, meta? }`.
- Web UI:
  - New route `/admin/users` with table + create/edit modal, role multi-select, delete.
  - Uses `web/src/lib/api.ts` helpers (`apiGet|apiPost|apiPatch|apiDelete`).
  - Login page at `/auth/login`; navbar renders consistently after bootstrap.
- Tests:
  - PHPUnit for UsersController (create/update/delete, role resolution, validation).
  - Vitest for Users page (load, create/update flows, 403 surface).
- OpenAPI: (additive) will be documented in Phase-6; endpoints remain internal/admin-only for now.

## Evidence & Exports (smoke)
- Evidence upload tests for allowed MIME and size (per Phase-4 contract).
- Export create/status tests for csv/json/pdf stubs.
- CSV export enabled by `capabilities.core.audit.export`.
  - **Headers:** `Content-Type: text/csv` (exact, no charset), `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, max-age=0`. ✅
- Capability gates covered by feature tests:
  - `AuditExportCapabilityTest` and `EvidenceUploadCapabilityTest` validate `403 CAPABILITY_DISABLED` + single deny audit. ✅
- **Throttling docs**: endpoints that opt into GenericRateLimit reference 429 in OpenAPI; budgets documented. ✅

## Config + Ops
- Runtime reads use config. `.env` only at bootstrap.
- All non-connection settings live in DB (`core_settings`); SettingsServiceProvider loads DB overrides at boot.
- CI checks for `env(` usage outside `config/`.
- Overlay file `/opt/phpgrc/shared/config.php` precedence confirmed.
- **Routing:** `bootstrap/app.php` sets API routing `prefix: ''` (no internal `/api`), Apache mounts at `/api/*`.
- **Apache:** SPA fallback present; assets long-cache; `index.html` no-store. `/api/*` aliased to Laravel public + FPM.
- After deploys: `php artisan config:clear && php artisan route:clear`.
- **New DB-backed knob:** `core.rbac.user_search.default_per_page` (int, 1–500, default 50). ✅
  - Set desired prod default; server still clamps to `[1..500]`. ✅
- **New API throttle knobs (global)**: `core.api.throttle.enabled|strategy|window_seconds|max_requests` + ENV mapping. ✅
- **No .env for app behavior**: all toggles except DB connection live in DB; docs and checks updated. ✅

## QA (manual)
- Admin, Auditor, unassigned user: verify access matrix.
- Capability off → Admin denied on guarded routes.
- Unknown policy key in persist → 403 + audit.
- Override to `[]` → stub allows, persist denies.
- **Deep-link reload:** `/admin/settings`, `/admin/users`, `/dashboard` load via history-mode fallback.
- **RBAC user search manual checks:** ✅
  - Omitting `per_page` uses DB default from Admin Settings.
  - Entering `per_page` outside range clamps to `[1..500]`.
  - Paging is stable by `id` and `meta.total`/`total_pages` update accordingly.
  - Admin Settings knob persists and survives reload.
- **OpenAPI endpoints:** Verify `/api/openapi.yaml` and `/api/openapi.json` return strong ETag, `no-store`, `nosniff`, optional `Last-Modified`, and honor `If-None-Match` (304). ✅
- **Rate limiting manual checks:** ✅
  - Trigger 429 on a throttled route; body matches `{ ok:false, code:"RATE_LIMITED", retry_after }` and headers set.
  - Verify `X-RateLimit-*` headers on 200 and 429.
  - `/auth/login` lock path returns `AUTH_LOCKED` with `Retry-After` and sets/reads session cookie when strategy=`session`.

## Docs
- ROADMAP and Phase-5 notes updated. ✅
- Dashboards contract and examples added. ✅
- Session log updated with decisions and KPI choices. ✅
- OpenAPI descriptions note KPI defaults and clamping. ✅
  - Note: Document alias `/api/metrics/dashboard` and optional `meta.window`.
- Ops runbook added at `docs/OPS.md`. ✅
- Redoc `x-logo.url` fixed to `/api/images/...`. ✅
- **RBAC user search docs:** Redoc snippet with paged examples, `Authorization` header example, clamping notes, and reference to DB default `core.rbac.user_search.default_per_page`. ✅
- **OpenAPI augmentation:** Runtime injection adds standard `401/403/422` where missing for protected endpoints; covered by tests. ✅
- **Rate limiting docs:** 429 response shape and headers documented; Redoc rendering fix (top-level `security` as array) noted. ✅
- **Admin Users (beta):** add short API & UI overview page; mark endpoints internal/admin-only pending Phase-6 spec exposure. ✅

## Deprecations (kept; do not remove)
- `MetricsThrottle` middleware (deprecated). Use `GenericRateLimit`.
- Legacy `core.metrics.throttle.*` knobs (deprecated) — forced disabled; retained for backward reference.
- ENV-based runtime toggles (deprecated for app behavior). Only DB-backed settings should be used at runtime; ENV reserved for bootstrap-only.

## Sign-off
- Security review note includes headers, APP_KEY env-only stance, and route mounting model.
- Release notes updated.
- **RBAC user search default-per-page** validated in API, UI, and tests. ✅
- **Rate limiting** unified via `GenericRateLimit` + exception normalization; tests updated. ✅
- **Admin Users Management (beta)** landed with API + UI + tests; marked internal for now. ✅
