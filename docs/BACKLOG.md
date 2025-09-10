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
- *NOTE: Redirect to setup until complete deferred to Phase 2*  
**Phase:** 1  
**Step:** 1  
**Dependencies:** None  
**Status:** Done

---

### CORE-002 — CI/CD Guardrails
**Description:** CI workflows: PHPUnit, PHPStan, Psalm, Pint, Spectral; conventional commits; branch protections.  
**Acceptance Criteria:**
- All guardrails green before merge  
- Guardrails enforced by branch protections  
**Phase:** 1  
**Step:** 2  
**Dependencies:** None  
**Status:** Done

---

### CORE-003 — Admin Settings UI
**Description:** Unified UI for system configs (auth, RBAC, evidence, exports, notifications, backups).  
**Acceptance Criteria:**
- Configs editable in UI (except emergency DB flag)  
- Changes audited  
**Phase:** 2  
**Step:** 1  
**Dependencies:** CORE-001  
**Status:** Done — API echo+validate, persistence applied with audited diffs to `audit_events`; tests updated.

---

### CORE-004 — RBAC Roles
**Description:** Roles Admin, Auditor, Risk Manager, User with namespaced permissions.  
**Acceptance Criteria:**
- Permissions follow `<module>.<action>` pattern  
- Enforced by Policies + Middleware  
**Phase:** 2  
**Step:** 2  
**Dependencies:** CORE-003  
**Status:** In progress — Sanctum PAT auth enabled; route-level checks active; fine-grained policies and self-serve role management pending.

---

### CORE-005 — Break-glass Login
**Description:** Emergency login pathway guarded by config flag.  
**Acceptance Criteria:**  
- Disabled by default, explicit env flag to enable  
**Phase:** 2  
**Step:** 3  
**Dependencies:** CORE-003  
**Status:** Done

---

### CORE-006 — Evidence
**Description:** Upload, store, retrieve evidence artifacts with audit trail.  
**Acceptance Criteria:**  
- Persist metadata and content  
- Audit all reads/writes  
**Phase:** 4  
**Step:** 1  
**Dependencies:** CORE-004  
**Status:** Done

---

### CORE-007 — Audit Trail
**Description:** Central audit log with retention.  
**Acceptance Criteria:**  
- `audit_events` table  
- API listing with bounds and pagination  
- Retention purge job  
**Phase:** 4  
**Step:** 2  
**Dependencies:** CORE-006  
**Status:** Done

---

### CORE-008 — Exports
**Description:** Export jobs with status and downloadable output.  
**Acceptance Criteria:**  
- Create job, poll status, download artifact  
- Evidence + audit linkages  
**Phase:** 4  
**Step:** 3  
**Dependencies:** CORE-004, CORE-006, CORE-007  
**Status:** In progress — endpoints stubbed; job model and generation pipeline next.

---

### CORE-009 — Swagger/OpenAPI
**Description:** Auto-generated OpenAPI 3 spec + SwaggerUI.  
**Acceptance Criteria:**
- `/api/openapi.json` generated  
- `/api/docs` served  
- CI lint with Spectral  
**Phase:** 5  
**Step:** 1  
**Dependencies:** None  
**Status:** Planned

---

### CORE-010 — Avatars
**Description:** User avatar upload/serve pipeline.  
**Acceptance Criteria:**  
- Validate size/format  
- Persist and serve via CDN path  
**Phase:** 4  
**Step:** 4  
**Dependencies:** CORE-004  
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
