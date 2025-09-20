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

---

# Theming & Layout — Phase 5.5 PR Checklist

## Gates
- OpenAPI updated for `/settings/ui`, `/me/prefs/ui`, `/settings/ui/brand-assets`, `/settings/ui/themes*` with examples.
- Spectral + openapi-diff pass.
- PHPStan/Psalm/PHPUnit green, Playwright snapshots recorded for Slate/Flatly/Darkly.

## RBAC + Audit
- Only `role_admin` or `admin.theme` hits `/settings/ui` and theme import routes.
- Audits emitted: `ui.theme.updated`, `ui.theme.overrides.updated`, `ui.brand.updated`, `ui.nav.sidebar.saved`, `ui.theme.pack.imported|deleted|enabled|disabled`.
- Sensitive bytes not stored in audit meta.

## Settings & Prefs
- Global settings persisted in DB; avatars on disk only.
- User prefs persisted: theme, mode, overrides, sidebar {collapsed,width,order}.
- Force-global respected; user light/dark allowed when theme supports both.

## Branding
- Upload validations: svg/png/jpg/jpeg/webp; ≤ 5 MB; MIME sniff; SVG sanitized.
- Favicon derive fallback works; disable footer logo option works.

## Theme Packs
- Zip import guardrails enforced (size, types, depth, filecount, ratio).
- JS/HTML scrubbed and not executed in 5.5. Manifest written.
- Delete purges DB and files; users fall back to default.
- Rate-limit: 5 imports per 10 minutes per admin.

## Layout & UX
- Top navbar shows core modules; logo sizing rules satisfied.
- Sidebar resizing bounds enforced; customization mode flow has Save/Cancel/Default/Exit and merge rules.

## Accessibility & Motion
- Contrast AA verified on key surfaces.
- `prefers-reduced-motion` honored; motion presets effective.

## No-FOUC
- Boot script sets `<html data-theme data-mode>` before CSS; SSR reload verified.

## Manual QA
- Human test checklist executed and returned with pass/fail notes; issues filed.

## Notices
- Bootstrap/Bootswatch license texts included; NOTICE updated.
