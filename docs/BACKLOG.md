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

---

### CORE-002 — CI/CD Guardrails
**Description:** Max strictness CI/CD (PHPCS, PHPStan L5, Psalm, Enlightn, composer-audit, Spectral, commitlint, CODEOWNERS).  
**Acceptance Criteria:**
- All guardrails green before merge  
- Guardrails enforced by branch protections  
**Phase:** 1  
**Step:** 2  
**Dependencies:** None  

---

### CORE-003 — Admin Settings UI
**Description:** Unified UI for system configs (auth, RBAC, evidence, exports, notifications, backups).  
**Acceptance Criteria:**
- All configs editable in UI (except emergency DB flag)  
- Changes audited  
**Phase:** 2  
**Step:** 1  
**Dependencies:** CORE-001  

---

### CORE-004 — RBAC Roles
**Description:** Roles Admin, Auditor, Risk Manager, User created with namespaced permissions.  
**Acceptance Criteria:**
- Permissions follow `<module>.<action>` pattern  
- Enforced by Policies + Middleware  
**Phase:** 2  
**Step:** 2  
**Dependencies:** CORE-003  

---

### CORE-005 — Break-glass Login
**Description:** Emergency local login path (DB flag only, MFA required).  
**Acceptance Criteria:**
- Hidden endpoint enabled only by DB flag  
- Full audit trail with justification, IP, UA  
**Phase:** 2  
**Step:** 3  
**Dependencies:** CORE-003  

---

### CORE-010 — Avatars
**Description:** User avatars (128px WEBP canonical).  
**Acceptance Criteria:**
- Upload any size; crop or resize to 128  
- Fallback initials or username  
**Phase:** 2  
**Step:** 4  
**Dependencies:** CORE-003  

---

### CORE-006 — Evidence Pipeline
**Description:** DB storage, SHA-256, versioning, attestation log.  
**Acceptance Criteria:**
- Default max 25 MB (configurable in UI)  
- Version history immutable  
**Phase:** 4  
**Step:** 1  
**Dependencies:** CORE-003  

---

### CORE-007 — Audit Trail
**Description:** DB log of every action.  
**Acceptance Criteria:**
- Retention configurable in UI  
- Max 2 years  
**Phase:** 4  
**Step:** 2  
**Dependencies:** CORE-003  

---

### CORE-008 — Exports
**Description:** Export endpoints for CSV, JSON, PDF (Dompdf).  
**Acceptance Criteria:**
- Job/status pattern  
- Stored in DB  
- Predefined reports only  
**Phase:** 4  
**Step:** 3  
**Dependencies:** CORE-006  

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

---

### RISK-002 — Risk Scoring
**Description:** Qualitative, quantitative, semi-quantitative scoring.  
**Acceptance Criteria:**
- Heatmaps, KRIs  
- Inherent vs residual  
**Phase:** 3  
**Step:** 2  
**Dependencies:** RISK-001  

---

### RISK-003 — Risk Treatment
**Description:** Accept, mitigate, transfer, avoid workflows.  
**Acceptance Criteria:**
- Workflow templates  
- Escalations  
**Phase:** 3  
**Step:** 3  
**Dependencies:** RISK-002  

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

---

### COMP-002 — Control Library
**Description:** Centralized controls repository.  
**Acceptance Criteria:**
- Controls CRUD  
- Linked to compliance obligations  
**Phase:** 3  
**Step:** 2  
**Dependencies:** COMP-001  

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

---

## 🔮 Future

- **FUT-001 — Task & Workflow Management**  
  Unified tasks across modules; workflows for approvals/remediation.  

- **FUT-002 — Third-Party Engagement Portal**  
  Vendor portal for questionnaires and evidence uploads.  

- **FUT-003 — Asset & Configuration Management**  
  Registry of assets; ingest from external sources via Integration Bus.  

- **FUT-004 — Indicators (KPI/KRI/KCI)**  
  Metric tracking, thresholds, alerts, dashboards.  

- **FUT-005 — Case Management (Whistleblower/Ethics)**  
  Anonymous case intake, secure workflows.  

- **FUT-006 — Integration Bus**  
  Connectors, pipelines, transforms, observability.  
