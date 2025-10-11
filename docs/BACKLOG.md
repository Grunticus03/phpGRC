# 📋 phpGRC Backlog
Complete backlog of all features, modules, and deliverables.  
Each item has: **id, module, title, description, acceptance_criteria, phase, step, deps, status**

---

## 🛠 Core

### CORE-001 — Bootstrap Installer & Setup Wizard
**Description:** Bootstrap script + setup webpage (DB config, SMTP, IdP, admin account).  
**Acceptance Criteria:**
- Installer fetches release
- Wizard stores DB config only on disk
- All other settings stored in DB
- Redirect-to-setup until complete deferred to Phase 2  
**Phase:** 1  
**Step:** 1  
**Dependencies:** None  
**Status:** Done

---

### CORE-002 — CI/CD Guardrails
**Description:** CI workflows: PHPUnit, PHPStan, Psalm, Pint, OpenAPI lint, breaking-change gate; conventional commits; branch protections.  
**Acceptance Criteria:**
- All guardrails pass on main
- Commit style enforced
- Fail-fast on static analysis and spec lint
- openapi-diff gate blocks breaking changes on PRs  
**Phase:** 1  
**Step:** 2  
**Dependencies:** None  
**Status:** Done

---

### CORE-003 — Admin Settings
**Description:** Admin Settings API and SPA to manage core config (RBAC, Audit, Evidence, Avatars).  
**Acceptance Criteria:**
- API accepts spec or legacy payload; normalizes to spec
- SPA renders and submits form with validation
- Errors standardized  
**Phase:** 4  
**Step:** 1  
**Dependencies:** CORE-002  
**Status:** Done

---

### CORE-004 — RBAC Roles
**Description:** Roles Admin, Auditor, Risk Manager, User with namespaced permissions.  
**Acceptance Criteria:**
- Role IDs are human-readable slugs `role_<slug>` with collision suffix `_N`
- Persistence path gated by `core.rbac.mode=persist` or `core.rbac.persistence=true`
- Endpoints: list/store roles; user-role list/replace/attach/detach
- Middleware enforces declared `roles`/`policy` defaults when enabled
- Admin UI for role catalog and user–role assignment  
**Phase:** 4  
**Step:** 2  
**Dependencies:** CORE-003  
**Status:** Done

---

### CORE-005 — Break-glass Login
**Description:** Emergency login pathway guarded by config flag.  
**Acceptance Criteria:**  
- Disabled by default, explicit env flag to enable
- Single-use token, strict audit trail  
**Phase:** 2  
**Step:** 3  
**Dependencies:** CORE-004  
**Status:** Done

---

### CORE-006 — Audit Trail
**Description:** Append-only audit events with retention limits.  
**Acceptance Criteria:**  
- `audit_events` table
- API listing with filters, bounds, and cursor pagination
- CSV export with `Content-Type: text/csv; charset=UTF-8`
- Retention purge job (≤ 2 years) + scheduler
- RBAC + categories helper  
**Phase:** 4  
**Step:** 2  
**Dependencies:** CORE-004  
**Status:** Done

---

### CORE-007 — Evidence Store
**Description:** Evidence upload, validation, storage, and retrieval.  
**Acceptance Criteria:**
- Validate size/mime by config
- Persist bytes and metadata in DB
- Compute and return SHA-256; set `ETag`, `X-Checksum-SHA256`
- `HEAD` semantics + conditional `If-None-Match` → `304`
- Optional `?sha256=<hex>` verification → `412 EVIDENCE_HASH_MISMATCH`
- List with filters and cursor pagination  
**Phase:** 4  
**Step:** 2  
**Dependencies:** CORE-003  
**Status:** Done

---

### CORE-008 — Exports
**Description:** Export jobs with status and downloadable output.  
**Acceptance Criteria:**  
- Create job, poll status, download artifact
- Types csv/json/pdf
- RBAC enforced; capability `core.exports.generate` gates creation  
**Phase:** 4  
**Step:** 3  
**Dependencies:** CORE-004, CORE-006, CORE-007  
**Status:** Done

---

### CORE-009 — Swagger/OpenAPI
**Description:** OpenAPI 3.1 spec served at `/api/openapi.yaml` with Swagger UI at `/api/docs`; lint and diff gates in CI.  
**Acceptance Criteria:**
- `/api/openapi.yaml` served and kept current
- Swagger UI reachable at `/api/docs`
- CI lint with Redocly
- openapi-diff breaking-change gate on PRs  
**Phase:** 5  
**Step:** 1  
**Dependencies:** CORE-002  
**Status:** Done

---

### CORE-010 — Avatars
**Description:** User avatars upload with format normalization.  
**Acceptance Criteria:**  
- Validate size/mime
- Normalize to WEBP, canonical size (128px)  
**Phase:** 4  
**Step:** 4  
**Dependencies:** CORE-003  
**Status:** Done

---

### CORE-011 — Runtime Settings Overlay (DB-backed)
**Description:** Move all runtime settings (non-connection) to DB table `core_settings`; overlay into `config()` at boot.  
**Acceptance Criteria:**
- Migration for `core_settings` (string PK `key`, longText `value`, `type=json`, timestamps)
- `SettingsServiceProvider` hydrates config from DB (safe when table missing)
- JSON-encoded values; decode on read; strict typing in overlay
- Feature tests: index reflects overrides; apply sets/unsets; partial updates don’t touch other keys
- Psalm/PHPStan green  
**Phase:** 5  
**Step:** 1  
**Dependencies:** CORE-003  
**Status:** Done

---

### CORE-012 — Admin Settings Persistence Path (apply=true)
**Description:** Enable persistence path for Admin Settings when `apply=true`; otherwise stub-only echo.  
**Acceptance Criteria:**
- `SettingsService::apply()` persists diffs; unsets rows when equal to defaults
- Actor id set when authenticated; audits emitted (Phase-4 format)
- Controller accepts spec and legacy shapes; normalizes
- Tests for stub-only vs persist modes  
**Phase:** 5  
**Step:** 1  
**Dependencies:** CORE-011  
**Status:** Done

---

### CORE-013 — Metrics Controller & Windows Clamp
**Description:** Internal admin-only KPIs endpoint with safe window parsing and clamping.  
**Acceptance Criteria:**
- `GET /api/dashboard/kpis` + alias `/api/metrics/dashboard`
- Query params: `days`, `rbac_days` clamped to `[1..365]`
- Response includes `meta.generated_at` and `meta.window`  
**Phase:** 5  
**Step:** 2  
**Dependencies:** CORE-006, CORE-007  
**Status:** Done

---

### CORE-014 — Apache Routing (Ops)
**Description:** Production vhost wiring for SPA + Laravel API.  
**Acceptance Criteria:**
- 80→443 redirect; HSTS; TLS chain configured
- `/api/` Alias to Laravel `public/` with `AllowOverride All` (mod_rewrite)
- SPA served from `/web` build; `/#/dashboard` default route
- Health and KPIs reachable under TLS  
**Phase:** 5  
**Step:** 2  
**Dependencies:** None  
**Status:** Done

---

### CORE-015 — OpenAPI JSON mirror (optional)
**Description:** Serve `/api/openapi.json` as a parity JSON representation of the YAML spec for integrators that require JSON.  
**Acceptance Criteria:**
- `GET /api/openapi.json` returns `application/json`
- Mirrors `docs/api/openapi.yaml` exactly
- PHPUnit feature test for MIME and JSON validity
- No change to existing `/api/openapi.yaml` or `/api/docs` default  
**Phase:** 5  
**Step:** 3  
**Dependencies:** CORE-009  
**Status:** Planned (low priority)

---

### CORE-016 — RBAC User Search Defaults (DB-backed)
**Description:** Paginated user search endpoint with stable `id` ordering and DB-backed default `per_page`; Admin Settings knob to control default page size.  
**Acceptance Criteria:**
- `/api/rbac/users/search` accepts `q`, `page`, `per_page`; clamps `per_page` to `[1..500]`.
- Controller reads `core.rbac.user_search.default_per_page` when `per_page` omitted; default 50.
- Admin Settings UI exposes numeric input under RBAC; persists to DB.
- Frontend consumer adopts `page`/`per_page` and respects `meta.total`/`total_pages`.  
**Phase:** 5  
**Step:** 2  
**Dependencies:** CORE-004, CORE-011, CORE-012  
**Status:** Done

---

## 🎨 UI / Theming (Phase 5.5)

### THEME-001 — Bootswatch Runtime Themes
**Description:** Ship full Bootswatch set; default Slate; light/dark toggle; system preference fallback.  
**Acceptance Criteria:**
- One theme stylesheet loaded at runtime
- `<html data-theme data-mode>` set before CSS to avoid FOUC
- User choice persisted; admin override respected but still allows light/dark if available
- Assets bundled locally; pinned to `bootswatch@5.3.3`; no CDN fetches  
**Phase:** 5.5  
**Step:** 1  
**Dependencies:** CORE-003  
**Status:** Planned

---

### THEME-002 — Theme Configurator (Admin)
**Description:** Admin UI to adjust tokens with live preview and guardrails.  
**Acceptance Criteria:**
- Tokens: color (picker & RGBA), shadow preset or validated custom, spacing preset, type scale preset, motion preset
- AA contrast validation on save
- Audits: `ui.theme.updated`, `ui.theme.overrides.updated`
- RBAC: `role_admin` or `role_theme_manager` (capability `admin.theme`) required; `role_theme_auditor` read-only  
**Phase:** 5.5  
**Step:** 2  
**Dependencies:** THEME-001  
**Status:** Planned

---

### THEME-003 — Per-user UI Preferences
**Description:** Allow user-level theme and allowed token overrides.  
**Acceptance Criteria:**
- `GET/PUT /me/prefs/ui` persists `theme`, `mode`, `overrides`, `sidebar` settings
- Guardrails identical to admin
- Admin “force global” disables theme select but not light/dark for supported themes
- Read-only via `ui.theme.view` (`role_theme_auditor`)  
**Phase:** 5.5  
**Step:** 3  
**Dependencies:** THEME-001  
**Status:** Done

---

### THEME-004 — Branding & Logos
**Description:** Manage primary/secondary/header/footer logos, favicon, and title text.  
**Acceptance Criteria:**
- Upload types: svg/png/jpg/jpeg/webp; ≤ 5 MB; MIME sniff; SVG sanitized
- Favicon generated if absent
- Header/footer logo defaults applied per spec
- Audit `ui.brand.updated`
- Assets stored in `ui_assets`; settings reference asset ULIDs  
**Phase:** 5.5  
**Step:** 4  
**Dependencies:** CORE-003  
**Status:** Done

---

### THEME-005 — Global Layout
**Description:** Standardize header/navbar, sidebar, and profile menu.  
**Acceptance Criteria:**
- Core modules in top navbar; non-core in sidebar
- Sidebar: collapsible, resizable (50px–50% viewport), customization mode with Save/Cancel/Default/Exit
- Merge rules for unseen modules; per-user persistence
- Branding logo placement and sizing per spec  
**Phase:** 5.5  
**Step:** 5  
**Dependencies:** THEME-001  
**Status:** Planned

---

### THEME-006 — Theme Pack Import
**Description:** Admin imports `.zip` packs (Bootswatch/StartBootstrap/BootstrapMade).  
**Acceptance Criteria:**
- Accept ≤ 50 MB zip; safe unzip; file allowlist
- JS/HTML stored but not executed in 5.5; scrubbed; manifest written
- Endpoints: import/list/update/delete; rate-limit 5/10min/admin
- Delete purges disk/DB and falls-back users to default; audited
- Store metadata in `ui_assets`; manifest registered in DB  
**Phase:** 5.5  
**Step:** 6  
**Dependencies:** THEME-002  
**Status:** Planned

---

### THEME-007 — Settings & APIs
**Description:** Settings schema and endpoints for global UI and per-user prefs.  
**Acceptance Criteria:**
- `/settings/ui`, `/me/prefs/ui`, `/settings/ui/brand-assets`, `/settings/ui/themes*`
- 422 invalid token; 413 oversize; 415 bad MIME
- OpenAPI documented; tests for RBAC and audits
- `ui_settings` table backed; endpoints use `If-Match` weak ETags; audits single entry per save  
**Phase:** 5.5  
**Step:** 7  
**Dependencies:** THEME-002, THEME-006  
**Status:** Done

---

### THEME-008 — Accessibility & Quality Gates
**Description:** Enforce a11y and visual baselines across themes.  
**Acceptance Criteria:**
- WCAG 2.2 AA contrast across key components
- prefers-reduced-motion respected; monthly locale smoke (`ar`, `ja-JP`)
- Playwright snapshots for Slate/Flatly (desktop+mobile), axe-core enforced
- Human Theming Checklist executed by QA owner; approvals stored  
**Phase:** 5.5  
**Step:** 8  
**Dependencies:** THEME-001  
**Status:** Planned

---

### THEME-009 — No-FOUC Boot Script
**Description:** Early theme application to prevent flash of unstyled content.  
**Acceptance Criteria:**
- Cookie read and `<html>` attributes set before CSS
- Degrades safely when cookie absent
- Tested in SSR and SPA reloads  
**Phase:** 5.5  
**Step:** 1  
**Dependencies:** THEME-001  
**Status:** Planned

---

## ⚠️ Risks
### RISK-001 — Risk Register
**Description:** Capture and categorize risks.  
**Acceptance Criteria:**
- Risk CRUD in DB  
- Categories configurable  
**Phase:** 3  
**Step:** 1  
**Dependencies:** CORE-004  
**Status:** Stubbed (Phase 3).

---

### RISK-002 — Risk Scoring
**Description:** Qualitative, quantitative, semi-quantitative scoring.  
**Acceptance Criteria:**
- Heatmaps, KRIs  
- Inherent vs residual  
**Phase:** 3  
**Step:** 2  
**Dependencies:** RISK-001  
**Status:** Stubbed (Phase 3).

---

### RISK-003 — Risk Treatment
**Description:** Accept, mitigate, transfer, avoid workflows.  
**Acceptance Criteria:**
- Workflow templates  
- Escalations  
**Phase:** 3  
**Step:** 3  
**Dependencies:** RISK-002  
**Status:** Stubbed (Phase 3).

---

## 📑 Compliance
### COMP-001 — Regulatory Library
**Description:** Catalog of regulations/frameworks.  
**Acceptance Criteria:**
- Framework CRUD  
- Link to controls  
**Phase:** 3  
**Step:** 1  
**Dependencies:** CORE-004  
**Status:** Stubbed (Phase 3).

---

### COMP-002 — Control Library
**Description:** Centralized controls repository.  
**Acceptance Criteria:
**- Controls CRUD  
- Linked to compliance obligations  
**Phase:** 3  
**Step:** 2  
**Dependencies:** COMP-001  
**Status:** Stubbed (Phase 3).

---

## 🔍 Audits
### AUD-001 — Audit Universe
**Description:** Audit areas, plans, scheduling.  
**Acceptance Criteria:**
- CRUD  
- Linked to risks/controls  
**Phase:** 3  
**Step:** 3  
**Dependencies:** RISK-001, COMP-002  
**Status:** Stubbed (Phase 3).

---

## 📜 Policies
### POL-001 — Policy Library
**Description:** Policy drafts, approvals, publishing, attestations.  
**Acceptance Criteria:**
- Policies versioned  
- Attestation workflows  
**Phase:** 3  
**Step:** 4  
**Dependencies:** CORE-004  
**Status:** Stubbed (Phase 3).

---

## 🚨 Incidents
### INC-001 — Incident Logging
**Description:** Classification, triage, escalation, closure.  
**Acceptance Criteria:**
- Incident CRUD  
- SLA tracking  
**Phase:** 6  
**Step:** 1  
**Dependencies:** CORE-004, RISK-001  
**Status:** Planned

---

## 🏢 Vendors
### VEN-001 — Vendor Inventory
**Description:** Centralized vendor catalog, risk tiering.  
**Acceptance Criteria:**
- Vendor CRUD  
- Risk scoring  
**Phase:** 6  
**Step:** 2  
**Dependencies:** CORE-004  
**Status:** Planned

---

## 🛡 Cyber
### CYB-001 — Cyber Metrics
**Description:** Integrate vulnerability scanners, SIEM, endpoint tools.  
**Acceptance Criteria:**
- Import metrics via Integration Bus  
- Residual risk calculations  
**Phase:** 6  
**Step:** 3  
**Dependencies:** CORE-004, FUT-006  
**Status:** Planned

---

## 🌐 BCP
### BCP-001 — BCP/DRP Management
**Description:** Business impact analysis, recovery objectives, crisis workflows.  
**Acceptance Criteria:**
- CRUD for plans  
- Link to incidents  
**Phase:** 6  
**Step:** 4  
**Dependencies:** CORE-004, INC-001  
**Status:** Planned

---

## 📊 Reporting
### REP-001 — Dashboards & Reports
**Description:** Role-based dashboards, risk heatmaps, compliance scorecards.  
**Acceptance Criteria:**
- Predefined reports  
- Role-based dashboards  
**Phase:** 5  
**Step:** 2  
**Dependencies:** CORE-008, RISK-002, COMP-002, AUD-001  
**Status:** In Progress (KPI v1 shipped: Evidence Freshness, RBAC Denies Rate; internal `GET /api/dashboard/kpis`; SPA tiles in Dashboard route)
- **Note:** DB-backed metrics defaults persisted (`core.metrics.evidence_freshness.days`, `core.metrics.rbac_denies.window_days`, `core.metrics.cache_ttl_seconds`). TTL enforcement in MetricsService pending.

---

## 🔮 Future
- **FUT-001 — Task & Workflow Management**  
  Unified tasks across modules; approvals/remediation.  
  **Status:** Planned

- **FUT-002 — Third-Party Engagement Portal**  
  Vendor portal for questionnaires and evidence uploads.  
  **Status:** Planned

- **FUT-003 — Asset & Configuration Management**  
  Registry of assets; ingest from external sources via Integration Bus.  
  **Status:** Planned

- **FUT-004 — Indicators (KPI/KRI/KCI)**  
  Metric tracking, thresholds, alerts, dashboards.  
  **Status:** Planned

- **FUT-006 — Integration Bus**  
  Connectors, pipelines, transforms, observability.  
  **Status:** Planned
