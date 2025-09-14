# @phpgrc:/docs/BACKLOG.md
# FILE: /docs/BACKLOG.md

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
- CSV export with `Content-Type: text/csv`
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
**Acceptance Criteria:**
- Controls CRUD  
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
**Status:** Planned

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
