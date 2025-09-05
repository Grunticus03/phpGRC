# Phase 4 — Core App Usable Detailed Task Breakdown

## Instruction Preamble
- Date 2025-09-06  
- Phase 4  
- Goal Decompose Phase 4 deliverables into detailed, sequential tasks to guide incremental scaffolding, enforcement, and persistence work.  
- Constraints  
  - No scope outside CharterBacklog.  
  - Each increment must pass CIguardrails.  
  - Stubs advance to enforcementpersistence gradually.  
  - Full traceability to Backlog IDs (CORE-003, CORE-004, CORE-006, CORE-007, CORE-008, CORE-010).  

---

## Task Checklist by Feature Area

### 1. Settings UI (CORE-003 expansion)
- [ ] Extend `apiappHttpControllersAdminSettingsController.php`
  - [ ] Implement `index()` to echo all `core.` config keys (rbac, audit, evidence, avatars).
  - [ ] Implement `update()` as no-op with schema validation + error codes.
- [ ] Add request validation rules per config section
  - [ ] RBAC enabled (bool).
  - [ ] Audit enabled (bool), retention_days (int).
  - [ ] Evidence enabled (bool), max_mb (int), allowed_mime (list).
  - [ ] Avatars enabled (bool), size_px (int), format (enum).
- [ ] Extend SPA routes
  - [ ] `websrcroutesadminSettings.tsx` — render form sections for RBAC, Audit, Evidence, Avatars.
  - [ ] Wire to `GET apiadminsettings`, display current values.
  - [ ] `PUT apiadminsettings` — stub call, display “not persisted yet”.
- [ ] Add unit tests for config echo + validation errors.
- [ ] Document new settings in `docscorePHASE-4-SPEC.md`.

### 2. RBAC Enforcement (CORE-004)
- [ ] Implement `AppHttpMiddlewareRbacMiddleware.php` enforcement
  - [ ] Read roles from `config('core.rbac.roles')`.
  - [ ] Match against placeholder `User` model roles[] property.
  - [ ] Errors UNAUTHORIZED, ROLE_NOT_FOUND.
- [ ] Add policy stubs
  - [ ] `apiappPoliciesPolicy.php` for Settings, Evidence, Audit.
  - [ ] Each policy returns false (stub-only).
- [ ] Update routes
  - [ ] Attach `RbacMiddleware` to `apiadminsettings` and `apirbac`.
- [ ] Extend SPA
  - [ ] `websrcroutesadminRoles.tsx` — render scaffold roles, disable editing.
- [ ] Migration alignment
  - [ ] Leave `roles` table stub-only until Phase 5 reconciliation.

### 3. Audit Trail (CORE-007)
- [ ] Extend `apiappHttpControllersAuditAuditController.php`
  - [ ] `index()` — return static JSON sample events.
  - [ ] Add pagination params (limit, cursor).
- [ ] Add model validation to `AuditEvent.php`.
- [ ] Define audit event categories in `docscorePHASE-4-SPEC.md`.
- [ ] Web route
  - [ ] `websrcroutesauditindex.tsx` — render sample audit table.
- [ ] Migration update
  - [ ] Keep `audit_events` stubbed; add detailed column comments.
- [ ] Plan integration for Phase 5 (real inserts on configauth changes).

### 4. Evidence Pipeline (CORE-006)
- [ ] Extend `apiappHttpControllersEvidenceEvidenceController.php`
  - [ ] Validate file size ≤ `core.evidence.max_mb`.
  - [ ] Validate mime ∈ `core.evidence.allowed_mime`.
  - [ ] Response `{okfalse, notestub-only}`
- [ ] Add request validation class `StoreEvidenceRequest`.
- [ ] SPA route
  - [ ] `websrcroutesevidenceindex.tsx` — file upload UI stub, show validations.
- [ ] Migration update
  - [ ] `evidence` table stub — add `sha256` column placeholder, comments.
- [ ] Future Phase 56 real DB writes + attestation log.

### 5. Exports (CORE-008 expansion from Phase 2)
- [ ] Extend `apiappHttpControllersExportExportController.php`
  - [ ] `create()` — accept type param (`csvjsonpdf`), return jobId stub.
  - [ ] `status()` — always pending, return fake progress.
  - [ ] `download()` — always 404 with EXPORT_NOT_READY.
- [ ] Update SPA
  - [ ] `websrcroutesexportsindex.tsx` — list fake jobs, status “pending”.
- [ ] Migration stub
  - [ ] Add `exports` table stub (id, type, params, status, created_at).
- [ ] Add docs section in PHASE-4-SPEC for job lifecycle.

### 6. Avatars (CORE-010)
- [ ] Extend `apiappHttpControllersAvatarAvatarController.php`
  - [ ] Validate file is image.
  - [ ] Validate ≤ `core.avatars.size_px  2` in widthheight.
  - [ ] Response `{okfalse, notestub-only}`
- [ ] SPA
  - [ ] `websrcroutesprofileAvatar.tsx` — upload UI stub, preview only.
- [ ] Migration update
  - [ ] `avatars` table stub — add unique constraint user_id.
- [ ] Future implement resize→128px WEBP + fallback initials.

---

## Cross-Cutting Tasks
- [ ] Update `docscorePHASE-4-SPEC.md` with final payloads, error taxonomy, schemas.
- [ ] Add unit tests for all new controllers (validate-only, no persistence).
- [ ] Ensure guardrails pass
  - [ ] PHPStanPSR-12 on new controllersmiddleware.
  - [ ] Spectral skip intact.
  - [ ] Composer audit unaffected.
- [ ] Update CAPABILITIES.md with status changes
  - [ ] Mark `core.rbac`, `core.audit.log`, `core.evidence.manage`, `core.exports`, `core.avatars` as ⏳ once scaffolds exist.
- [ ] Add RFCs if new module interactions required.

---

## Sequencing (Recommended Order)
1. Expand Settings UI (echo+validation).  
2. Implement RBAC middleware + roles display.  
3. Add Audit Trail sample events.  
4. Wire Evidence validations + upload stub.  
5. Extend Exports controller + stub job lifecycle.  
6. Add Avatar validations + upload stub.  
7. Update docstests across all.  
8. Confirm CI guardrails green.  

---

## Acceptance Criteria for Phase 4 Completion
- Settings UI fully echoes all configs, validates updates.  
- RBAC enforcement functional at middlewarepolicy level.  
- Audit Trail stubs return sample events, retention config exposed.  
- Evidence uploads validated, no storage yet.  
- Exports follow jobstatus pattern, no real jobs.  
- Avatars validated, no processing yet.  
- All docs, specs, and migrations updated.  
- CI green with guardrails enforced.  
