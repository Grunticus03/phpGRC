# Phase 4 — Core App Usable Detailed Task Breakdown

## Instruction Preamble
- Date 2025-09-07
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
- [x] Extend `Admin/SettingsController.php` with echo + validation stubs
- [x] Validation rules for RBAC, Audit, Evidence, Avatars
- [x] Extend SPA `/web/src/routes/admin/Settings.tsx`
- [ ] Add unit tests
- [ ] Document payloads and error codes in PHASE-4-SPEC.md

### 2. RBAC Enforcement (CORE-004)
- [x] Add `RbacMiddleware.php` (tag-only, no enforcement)
- [x] Add `RolesController.php` stub + SPA `/web/src/routes/admin/Roles.tsx`
- [ ] Add policy stubs for Settings, Evidence, Audit
- [x] Attach middleware to `/api/admin/*` and `/api/rbac/*`
- [ ] Migration alignment deferred

### 3. Audit Trail (CORE-007)
- [x] `AuditController.php` with static sample events + SPA `/web/src/routes/audit/index.tsx`
- [x] `AuditEvent.php` placeholder model
- [x] Migration stub `audit_events`
- [ ] Define categories and retention config in spec

### 4. Evidence Pipeline (CORE-006)
- [x] `EvidenceController.php` with validations
- [x] `StoreEvidenceRequest.php`
- [x] SPA `/web/src/routes/evidence/index.tsx`
- [x] Migration stub `evidence` with sha256
- [x] `Evidence.php` model

### 5. Exports (CORE-008 expansion)
- [x] Extend `ExportController.php` with `create` + `download`
- [x] `StatusController.php`
- [x] SPA `/web/src/routes/exports/index.tsx`
- [x] Migration stub `exports`

### 6. Avatars (CORE-010)
- [x] `AvatarController.php` with validation stub
- [x] `StoreAvatarRequest.php`
- [x] SPA `/web/src/routes/profile/Avatar.tsx`
- [x] Migration stub `avatars` with unique user_id
- [x] `Avatar.php` model

---

## Cross-Cutting Tasks
- [ ] Update PHASE-4-SPEC.md with final payloads and errors
- [ ] Update CAPABILITIES.md to mark Phase-4 features ⏳
- [ ] Add unit tests for new controllers
- [ ] CI guardrails: PHPStan/PSR-12/commitlint/etc.

---

## Sequencing (Completed)
1. Settings UI scaffolds  
2. RBAC middleware + roles display  
3. Audit Trail stub events  
4. Evidence upload stub  
5. Exports stub lifecycle  
6. Avatars stub  

---

## Acceptance Criteria for Phase 4 Completion
- [x] Settings UI echoes configs, validates updates
- [x] RBAC middleware present, roles scaffolded
- [x] Audit Trail stub returns events
- [x] Evidence validated, no storage
- [x] Exports follow job/status pattern
- [x] Avatars validated, no storage
- [ ] Docs/specs/tests updated
- [ ] CI green
