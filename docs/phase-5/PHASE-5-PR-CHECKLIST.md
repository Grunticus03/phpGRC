# Phase 5 — Minimal PR Checklist

## Gates
- PHPStan level 9: ✅
- Psalm: ✅
- PHPUnit: ✅ all suites
- OpenAPI:
  - `openapi-diff` against 0.4.6: no breaking changes unless approved
  - **Redocly lint**: ✅

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
- **RBAC user search**:
  - `/api/rbac/users/search` paginates with `page` and `per_page`; ordered by `id` ascending for stable results. ✅
  - Server clamps `per_page` to `[1..500]`. ✅
  - Default `per_page` comes from DB setting `core.rbac.user_search.default_per_page` (range 1–500, default 50). Admin Settings GUI knob added. ✅
  - Note: If any consumer still assumes unpaged search, keep item and fix in follow-up; current UI adopts paging. (left for traceability)

## Audit
- Emit `RBAC` denies with canonical actions:
  - `rbac.deny.capability`, `rbac.deny.unauthenticated`, `rbac.deny.role_mismatch`, `rbac.deny.policy`.
- Emit `AUTH` events: `auth.login.success|failed|logout|break_glass.*`, `auth.bruteforce.locked`.
- PolicyMap override safety: unknown roles in overrides audited as `rbac.policy.override.unknown_role` with `meta.unknown_roles`.
- `actor_id` rules:
  - `null` when unauthenticated or auth fails.
  - user id on success.
- UI label mapping for `rbac.deny.*` integrated via `web/src/lib/audit/actionInfo.ts` with tests. ✅

## Dashboards
- Implement 2 KPIs first: Evidence freshness, RBAC denies rate. ✅
- Internal endpoint `GET /api/dashboard/kpis` (Admin-only) without OpenAPI change. ✅
  - Note: Response may include optional `meta.window: { rbac_days, fresh_days }` for display.
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

## Evidence & Exports (smoke)
- Evidence upload tests for allowed MIME and size (per Phase-4 contract).
- Export create/status tests for csv/json/pdf stubs.
- CSV export enabled by `capabilities.core.audit.export`; header `Content-Type: text/csv; charset=UTF-8`.

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

## QA (manual)
- Admin, Auditor, unassigned user: verify access matrix.
- Capability off → Admin denied on guarded routes.
- Unknown policy key in persist → 403 + audit.
- Override to `[]` → stub allows, persist denies.
- **Deep-link reload:** `/admin/settings` and `/dashboard` load via history-mode fallback.
- **RBAC user search manual checks:** ✅
  - Omitting `per_page` uses DB default from Admin Settings.
  - Entering `per_page` outside range clamps to `[1..500]`.
  - Paging is stable by `id` and `meta.total`/`total_pages` update accordingly.
  - Admin Settings knob persists and survives reload.

## Docs
- ROADMAP and Phase-5 notes updated. ✅
- Dashboards contract and examples added. ✅
- Session log updated with decisions and KPI choices. ✅
- OpenAPI descriptions note KPI defaults and clamping. ✅
  - Note: Document alias `/api/metrics/dashboard` and optional `meta.window`.
- Ops runbook added at `docs/OPS.md`. ✅
- Redoc `x-logo.url` fixed to `/api/images/...`. ✅
- **RBAC user search docs:** Redoc snippet with paged examples, `Authorization` header example, clamping notes, and reference to DB default `core.rbac.user_search.default_per_page`. ✅


## Sign-off
- Security review note includes headers, APP_KEY env-only stance, and route mounting model.
- Release notes updated.
- **RBAC user search default-per-page** validated in API, UI, and tests. ✅
