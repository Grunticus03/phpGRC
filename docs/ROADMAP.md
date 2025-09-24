# @phpgrc:/ROADMAP.md
# phpGRC — ROADMAP (Aligned to Charter v1.1)

> Source of truth for phase gating. Each checkbox must be merged & deployed with green guardrails before moving on.

---

## Phase 0 — Docs-first foundation ✅ COMPLETE
- [x] Charter v1.1 committed
- [x] Start ROADMAP.md
- [x] Seed BACKLOG.md
- [x] Create CAPABILITIES.md
- [x] Add RFC template `docs/rfcs/000-template.md`

---

## Phase 1 — Guardrails + Setup baseline ✅ COMPLETE
- [x] Repo structure established (`/api`, `/web`, `/docs`, `.github`, `/scripts`)
- [x] CI/CD skeleton (`.github/workflows/ci.yml`) green
- [x] Deploy workflow to test target
- [x] HTTPS placeholder served
- [x] CORE-001 installer + setup wizard stubs

---

## Phase 2 — Auth/Routing ✅ COMPLETE
- [x] Laravel API skeleton reachable
- [x] Auth scaffolds (login/logout/me)
- [x] TOTP scaffolds
- [x] Break-glass guard scaffold
- [x] Admin Settings UI framework (skeleton)
- [x] Early Exports stub endpoints

---

## Phase 3 — Module foundation ✅ COMPLETE
- [x] ModuleManager + `module.json` schema
- [x] Capabilities registry
- [x] Stubs for Risks, Compliance, Audits, Policies modules

---

## Phase 4 — Core app usable ✅ COMPLETE (frozen 2025-09-19)
- [x] Settings — echo + validation stubs
- [x] RBAC — Sanctum PAT guard; route enforcement; JSON 401/403 contract
- [x] RBAC — role IDs standardized to human-readable slugs
- [x] RBAC — admin UI for role list/create and user–role assign
- [x] Audit — listing, categories helper, retention echo
- [x] Audit — CSV export with exact `Content-Type: text/csv` and cursor streaming
- [x] Evidence — file uploads accepted (Phase-4 policy: no MIME/size validation)
- [x] Evidence persistence: DB storage + sha256 + listing + headers + conditional GET + hash verification
- [x] Audit persistence: write path + retention enforcement (≤ 2 years)
- [x] API docs for Settings/Audit/Evidence + common errors
- [x] Feature tests for Settings/Audit/Evidence + RBAC middleware tagging
- [x] Exports job model + generation (CSV/JSON/PDF) + download with headers
- [x] Settings persistence + audit logging of applied changes
- [x] Stub-path audit response covered by tests
- [x] CSV large-dataset smoke for SQLite
- [x] Ops docs: retention runbook + audit config notes
- [x] OpenAPI 0.4.6 validated and served
- [x] Static analysis: PHPStan level 9 enforced in CI

---

## Phase 5 — Swagger + dashboards + RBAC policies
- [x] OpenAPI served at `/api/openapi.yaml` and Swagger UI at `/api/docs`
- [x] OpenAPI lint in CI (Redocly)
- [x] Breaking-change gate (openapi-diff) in CI
- [x] RBAC deny audits emitted by middleware (one per denied request)
- [x] KPIs v1 (admin-only): RBAC denies rate (rolling window), Evidence freshness (threshold + by-MIME)
- [x] Auth brute-force guard (session/IP strategies) with `auth.login.failed|locked` audits
- [x] **OpenAPI serve headers hardened**: exact MIME, `ETag`, conservative caching, `nosniff`
- [ ] Fine-grained RBAC policies (PolicyMap/Evaluator) and role management UI hardening
- [ ] Predefined reports & dashboards (beyond KPIs v1)
- [ ] Admin/User docs baseline

### Phase 5 — Additions (2025-09-23..2025-09-24)
- [x] **Runtime settings moved to DB** for all non-connection knobs; `core_settings` table + `SettingsServiceProvider` boot overlay.
- [x] **Admin Settings persistence path**: `apply=true` writes to DB; stub-only honored when configured.
- [x] **Metrics routes finalized**: `GET /api/dashboard/kpis` and alias `GET /api/metrics/dashboard`; controller clamps windows and returns `meta.window`.
- [x] **Web UI settings form** updated to DB-backed metrics fields; Vitest adjusted for PUT and stub/persist modes.
- [x] **Apache deploy verified**: `/api/*` routes to Laravel public with `AllowOverride All` and `mod_rewrite`; health and KPIs reachable.
- [ ] **KPI cache TTL** stored in DB (`core.metrics.cache_ttl_seconds`) and enforced in service layer.  
  - Child note: TTL key exists; enforcement to be implemented in MetricsService adapter.
- [ ] **Optional** `/api/openapi.json` mirror for integrators (tracked in Backlog).

---

## Phase 5.5 — Theming & Layout
- [ ] Bootswatch theme set shipped; default **Slate**; light/dark toggle; respects system when no user choice.
- [ ] No-FOUC boot: early `<html data-theme data-mode>` script using cookie; SSR-safe.
- [ ] Design tokens & presets:
  - Color: full picker (wheel, hex, RGB, eyedropper). Persist RGBA.
  - Shadow: `none | default | light | heavy | custom(validated)`.
  - Spacing: `narrow | default | wide`.
  - Type scale: `small | medium | large`.
  - Motion: `full | limited | none`.
- [ ] Admin Theme Configurator with live preview, AA contrast guardrails, strict validation (422 on unsafe).
- [ ] Per-user theme and token overrides; admin “force global” still allows light/dark if available.
- [ ] RBAC: only `role_admin` or permission `admin.theme` can change global settings/import themes.
- [ ] Branding: primary/secondary/header/footer logos, favicon, title text; SVG sanitized; ≤ 5 MB each; defaults applied.
- [ ] Global layout:
  - Top navbar lists core modules; brand logo top-left acts as Home; sizing rules enforced.
  - Sidebar holds non-core modules; collapsible; user-resizable (min 50px, max 50% viewport).
  - Sidebar customization mode (long-press): Save/Cancel/Default/Exit; merge rules for new modules; per-user persistence.
  - User profile menu on right with profile/lock/logout entries (lock UX routed; full behavior Phase 6).
- [ ] Settings & APIs:
  - Global: `GET/PUT /settings/ui`, `POST/DELETE /settings/ui/brand-assets`.
  - Per-user: `GET/PUT /me/prefs/ui`.
  - Themes: `GET /settings/ui/themes`, `POST /settings/ui/themes/import`, `PUT/DELETE /settings/ui/themes/{slug}`.
- [ ] Theme pack import:
  - Accept `.zip` ≤ 50 MB; allow `.css .scss .woff2 .png .jpg .jpeg .webp .svg .map .js .html`.
  - JS/HTML stored but not executed in 5.5; scrubbed; manifest recorded; rate-limit 5/10min/admin.
  - Safe unzip (no traversal/symlinks; depth ≤10; files ≤2000; ratio guard).
  - Delete always permitted; users fall back to default; purge disk; audit.
- [ ] Tests: unit/feature for settings, prefs, RBAC, audits; Playwright snapshots for Slate/Flatly/Darkly; e2e for theme switch, override, sidebar flow.
- [ ] A11y: WCAG 2.2 AA, focus-visible, reduced motion honored; import shows “A11y warnings” if contrast risky.
- [ ] Notices: Bootstrap/Bootswatch licenses added to NOTICE.

---

## Phase 6 — Integrations
- [ ] Integration Bus MVP (connectors, pipelines, transforms, observability)
- [ ] External Auth providers (OIDC/SAML/LDAP/Entra)
- [ ] Asset ingestion (CMDB, cloud, IPAM)
- [ ] Indicator framework
- [ ] BCP/DRP workflows, Vendor inventory, Incident logging
- [ ] Optional: sandboxed theme-pack JS/HTML enablement (separate origin/CSP)

---

## Phase 7 — Release v1.0
- [ ] Prod deploy workflows
- [ ] Hardening & docs
- [ ] Release tag `v1.0.0`

---

### Current Status (as of 2025-09-24)
- ✅ Phase 4 frozen; CI green; contracts locked; OpenAPI 0.4.6 validated.
- ✅ RBAC enforcement active; admin UI shipped.
- ✅ Audit & Evidence persistence complete; CSV export streaming with bounded memory.
- ✅ Exports model and generation complete.
- ✅ CI lint (Redocly) and breaking-change gate (openapi-diff).
- ✅ Static analysis: PHPStan level 9 enforced in CI.
- ⏳ Phase 5 in progress: KPIs v1 shipped, deny-audit invariants enforced, brute-force guard in place, OpenAPI serve hardened.
- 🔜 Phase 5.5 planned: theming/layout scope accepted; see BACKLOG and SPEC.
