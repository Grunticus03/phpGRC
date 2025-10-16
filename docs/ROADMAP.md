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

## Phase 5 — Swagger + dashboards + RBAC policies ✅ COMPLETE (2025-09-28)
- [x] OpenAPI served at `/api/openapi.yaml` and Swagger/Redoc UI at `/api/docs`
- [x] OpenAPI lint in CI (Redocly)
- [x] Breaking-change gate (openapi-diff) in CI
- [x] RBAC deny audits emitted by middleware (one per denied request)
- [x] KPIs v1 (admin-only): RBAC denies rate (rolling window), Evidence freshness (threshold + by-MIME)
- [x] Auth brute-force guard (session/IP strategies) with `auth.login.failed|locked` audits
- [x] **OpenAPI serve headers hardened**: exact MIME, `ETag`, conservative caching, `nosniff`
- [x] **Generic API rate limiting** finalized: unified 429 envelope + headers; route-level throttle defaults
- [x] **OpenAPI augmentation (runtime)**: inject standard `401/403/422` + additive `429 RateLimited`
- [x] **RBAC user search pagination** + DB default `per_page` knob; Admin Settings + UI adoption
- [x] **SPA auth bootstrap**: WebUI redirects to `/auth/login` only when `require_auth=true` and session probe fails
- [x] **Navbar boot fix**: navigation renders after bootstrap probe; no blank layout
- [x] Fine-grained RBAC policies (PolicyMap/Evaluator) and role management UI hardening
- [x] Predefined reports & dashboards (beyond KPIs v1)
- [ ] Admin/User docs baseline *(deferred to Phase 6 release docs backlog)*
- [ ] **Phase 6: Granular permissions UI** — permission catalog, API, and role assignment refinements (see Backlog item)

### Phase 5 — Additions (2025-09-23..2025-09-28)
- [x] **Runtime settings moved to DB** for all non-connection knobs; `core_settings` table + `SettingsServiceProvider` boot overlay.
- [x] **Admin Settings persistence path**: `apply=true` writes to DB; stub-only honored when configured.
- [x] **Metrics routes finalized**: `GET /api/dashboard/kpis` and alias `GET /api/metrics/dashboard`; controller clamps windows and returns `meta.window`.
- [x] **Web UI settings form** updated to DB-backed metrics fields; Vitest adjusted for PUT and stub/persist modes.
- [x] **Apache deploy verified**: `/api/*` routes to Laravel public; health and KPIs reachable.
- [x] **Admin Users Management (beta)**: `/users` API + Web UI for list/create/update/delete; role assign supported.  
      Fine-grained per-permission toggles **planned** (tracked for Phase 5 hardening).
- [x] **KPI tests (Vitest)** stabilized with Response-like mocks; clamping verified; alias route parity covered.
- [x] **Navbar & layout**: AppLayout bootstrap sequence loads config → session probe; Nav renders post-probe.
- [x] **DB-as-source-of-truth**: removed runtime `.env` dependence for app behavior (DB only, except DB connection).
- [x] **Role management polish**: Admin API supports rename/delete with audits; RBAC user search accepts field filters (`name:`, `email:`, `id:`, `role:`).

- [x] **KPI cache TTL** stored in DB (`core.metrics.cache_ttl_seconds`) and enforced in service layer.  
  - Child note: TTL key exists; enforcement to be implemented in MetricsService adapter.
- [x] **Optional** `/api/openapi.json` mirror for integrators (tracked in Backlog).
- [x] **Docs**: Redoc paged example for RBAC user search and auth header notes.

---

## Phase 5.5 — Theming & Layout
- [x] Bootswatch theme set shipped; default **Slate**; light/dark toggle; respects system when no user choice; assets bundled locally with pinned `bootswatch@5.3.8`.
- [x] No-FOUC boot: early `<html data-theme data-mode>` script using cookie/localStorage; SSR-safe and inline.
- [x] Design tokens & presets:
  - Color: full picker (wheel, hex, RGB, eyedropper). Persist RGBA.
  - Shadow: `none | default | light | heavy | custom(validated)`.
  - Spacing: `narrow | default | wide`.
  - Type scale: `small | medium | large`.
  - Motion: `full | limited | none`.
- [x] Admin Theme Configurator with live preview, AA contrast guardrails, strict validation (422 on unsafe).
- [x] Per-user theme and token overrides; admin “force global” still allows light/dark for supported themes.
- [x] RBAC: seed `role_theme_manager` (manage/import) and `role_theme_auditor` (read-only) plus `ui.theme.*` policies guarded by `core.theme.view|manage|pack.manage`.
- [x] Branding: primary/secondary/header/footer logos, favicon, title text; SVG sanitized; ≤ 5 MB each; defaults applied.
- [x] Global layout:
  - Top navbar lists core modules; brand logo top-left acts as Home; sizing rules enforced.
  - Sidebar holds non-core modules; collapsible; user-resizable (min 50px, max 50% viewport).
  - Sidebar customization mode (long-press): Save/Cancel/Default/Exit; merge rules for new modules; per-user persistence.
  - User profile menu on right with profile/lock/logout entries (lock UX routed; full behavior Phase 6).
- [x] Settings & APIs:
  - Global: `GET/PUT /settings/ui`, `POST/DELETE /settings/ui/brand-assets`.
  - Per-user: `GET/PUT /me/prefs/ui`.
  - Themes: `GET /settings/ui/themes`, `POST /settings/ui/themes/import`, `PUT/DELETE /settings/ui/themes/{slug}`.
- [x] Theme pack import:
  - Accept `.zip` ≤ 50 MB; allow `.css .scss .woff2 .png .jpg .jpeg .webp .svg .map .js .html`.
  - JS/HTML stored but not executed in 5.5; scrubbed; manifest recorded; rate-limit 5/10min/admin.
  - Safe unzip (no traversal/symlinks; depth ≤10; files ≤2000; ratio guard).
  - Delete always permitted; users fall back to default; purge disk; audit.
- [ ] Tests: unit/feature for settings, prefs, RBAC, audits; Playwright snapshots for Slate/Flatly/Darkly; e2e for theme switch, override, sidebar flow.
- [ ] A11y & QA: automated axe checks, Playwright snapshots (Slate/Flatly desktop+mobile), manual theming checklist executed each PR; reduced-motion honored.
- [ ] Notices & licensing: Bootstrap/Bootswatch texts in NOTICE; uploaded packs append vendor LICENSE metadata.

> **Next up:** focus on THEME-005 Global Layout, no-FOUC boot script, and the remaining accessibility & Playwright gates before closing Phase 5.5.

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

### Current Status (as of 2025-09-28)
- ✅ Phase 4 frozen; CI green; contracts locked; OpenAPI 0.4.6 validated.
- ✅ RBAC enforcement active; admin UI shipped.
- ✅ Audit & Evidence persistence complete; CSV export streaming with bounded memory.
- ✅ Exports model and generation complete.
- ✅ CI lint (Redocly) and breaking-change gate (openapi-diff).
- ✅ Static analysis: PHPStan level 9 enforced in CI.
- ✅ Phase 5 complete: KPIs v1 shipped; deny-audit invariants enforced; brute-force guard in place; OpenAPI serve hardened; **generic API rate limiting and OpenAPI augmentation completed; Admin Users Management (beta) added; SPA auth bootstrap + navbar boot fixed.**
- ➕ RBAC user search pagination and DB-backed default `per_page` knob completed; docs snippet pending.
- 🔜 Phase 5.5 planned: theming/layout scope accepted; see BACKLOG and SPEC.
