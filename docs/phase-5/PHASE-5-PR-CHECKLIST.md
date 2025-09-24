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
- **Settings persistence:** Admin `PUT /api/admin/settings` accepts `apply: true` to persist, or returns stub note when persistence unavailable. ✅
- **Apache routing:** SPA served at `/`; `/api/` reverse-proxies to Laravel public (or internal vhost at 127.0.0.1:8081). ✅
  - Note: After deploys, run `php artisan config:clear` and `php artisan route:clear` to avoid stale caches.

## QA (manual)
- Admin, Auditor, unassigned user: verify access matrix.
- Capability off → Admin denied on guarded routes.
- Unknown policy key in persist → 403 + audit.
- Override to `[]` → stub allows, persist denies.

## Docs
- ROADMAP and Phase-5 notes updated. ✅
- Dashboards contract and examples added. ✅
- Session log updated with decisions and KPI choices. ✅
- OpenAPI descriptions note KPI defaults and clamping. ✅
  - Note: Document alias `/api/metrics/dashboard` and optional `meta.window`.
- Ops runbook added at `docs/OPS.md`. ✅

## Sign-off
- Security review note.
- Release notes drafted.
