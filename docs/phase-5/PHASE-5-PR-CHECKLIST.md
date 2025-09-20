# Phase 5 — Minimal PR Checklist

## Gates
- PHPStan level 9: ✅
- Psalm: ✅
- PHPUnit: ✅ all suites
- OpenAPI:
  - `openapi-diff` against 0.4.6: no breaking changes unless approved
  - Spectral lint: ✅

## RBAC and Auth
- `core.rbac.policies` updates documented in RBAC notes.
- Middleware denies return 403 with custom permission page when RBAC enabled.
- `/api/rbac/*` returns 404 only when RBAC is disabled.
- Auth brute-force settings wired:
  - `core.auth.bruteforce.enabled`
  - `strategy: ip|session` (default session)
  - `window_seconds`, `max_attempts` (default 900s, 5)
  - Session cookie drops on first attempt.
  - Lock emits 429 with Retry-After; audited as `auth.login.locked`.

## Audit
- Emit `RBAC` denies with canonical actions.
- Emit `AUTH` events: `auth.login.success|failed|logout|break_glass.*`.
- `actor_id` rules:
  - `"anonymous"` only when no identifier provided.
  - `null` when identifier provided but auth fails.
  - user ULID on success.
- UI shows human labels with action code chip.

## Dashboards
- Implement 2 KPIs first: Evidence freshness, RBAC denies rate. ✅
- Internal endpoint `GET /api/dashboard/kpis` (Admin-only) without OpenAPI change. ✅
- Seed data fixtures for audit/evidence. ✅
- Feature tests cover 401/403 and data correctness. ✅
- Performance check documented. ✅

## Evidence & Exports (smoke)
- Upload tests for allowed MIME and size ignoring config in Phase-4 stubs.
- Export create/status tests for csv/json/pdf stubs.
- CSV export toggled by `capabilities.core.audit.export`.

## Config + Ops
- Runtime reads use config. `.env` only at bootstrap.
- CI checks for `env(` usage outside `config/`.
- Overlay file `/opt/phpgrc/shared/config.php` precedence confirmed.

## QA (manual)
- Admin, Auditor, unassigned user: verify access matrix.
- Capability off → Admin denied on guarded routes.
- Unknown policy key in persist → 403 + audit.
- Override to `[]` → stub allows, persist denies.

## Docs
- Update ROADMAP and Phase-5 notes. ✅
- Add dashboards contract and examples. ✅
- Session log updated with decisions and KPI choices. ✅

## Sign-off
- Security review note.
- Release notes drafted.