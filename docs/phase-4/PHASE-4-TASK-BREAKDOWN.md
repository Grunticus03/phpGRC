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
- [x] Add unit tests (`SettingsValidationTest`)
- [ ] Document payloads and error codes in PHASE-4-SPEC.md
- [x] Document API in `/docs/api/SETTINGS.md` and `/docs/api/ERRORS.md`

### 2. RBAC Enforcement (CORE-004)
- [x] Add `RbacMiddleware.php` (tag-only, no enforcement)
- [x] Add `RolesController.php` stub + SPA `/web/src/routes/admin/Roles.tsx`
- [x] Add policy stubs for Settings, Evidence, Audit
- [x] Attach middleware to `/api/admin/*` and `/api/rbac/*`
- [x] Register gates in `AuthServiceProvider` + provider registration in `bootstrap/app.php`
- [ ] Enforcement pass + DB role-binding deferred
- [x] Add middleware test (`RbacPolicyTest`)

### 3. Audit Trail (CORE-007)
- [x] `AuditController.php` with stub fallback and DB-backed listing
- [x] `AuditEvent.php` model + `...create_audit_events_table.php` migration
- [x] Define categories helper and retention config
- [x] Persist audit events for evidence actions (upload/read/head) and settings updates
- [x] Retention purge job honoring `core.audit.retention_days`
- [x] Feature tests (`AuditApiTest`)

### 4. Evidence Pipeline (CORE-006)
- [x] `EvidenceController.php` with validations
- [x] `StoreEvidenceRequest.php` (unified error envelope)
- [x] SPA `/web/src/routes/evidence/index.tsx`
- [x] Migration `...create_evidence_table.php` with sha256 + LONGBLOB
- [x] `Evidence.php` model
- [x] Persist bytes + sha256 + per-filename versioning
- [x] `GET/HEAD /api/evidence/{id}` with `ETag`, `Content-Length`, `nosniff`, attachment disposition
- [x] `GET /api/evidence?limit&cursor` opaque cursor pagination (+ created_at,id index)
- [x] Feature tests: upload, retrieve, ETag 304, pagination, 404, disabled path
- [x] Audit hooks for upload/read/head
- [x] Document API in `/docs/api/EVIDENCE.md`

### 5. Exports (CORE-008 expansion)
- [x] Extend `ExportController.php` with `create` + `download`
- [x] `StatusController.php`
- [x] SPA `/web/src/routes/exports/index.tsx`
- [x] Migration stub `exports`
- [ ] Job model and artifact generation (CSV/JSON/PDF)

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
- [x] Add unit/feature tests for settings/audit/evidence controllers
- [x] CI guardrails: Pint / PHPStan / Psalm / PHPUnit on PRs
- [x] Composer bootstrap: `scripts/composer/app-prepare.php`, `app:prepare` hook
- [x] Keepers: `bootstrap/cache`, `storage/**` `.gitignore` committed
- [x] Config templates: `api/.env.example`, `scripts/templates/shared-config.php`
- [x] CI DB service: MySQL container + provisioning
- [x] Docs: `/docs/DEV-SETUP.md`, `/docs/MAKE-TARGETS.md`
- [x] API docs for Settings/Audit/Evidence + common errors

---

## Sequencing (Completed)
1. Settings UI scaffolds  
2. RBAC middleware + roles display  
3. Audit Trail stub events  
4. Evidence upload stub  
5. Exports stub lifecycle  
6. Avatars stub  
7. Evidence persistence + retrieval endpoints  
8. CI/dev bootstrap hardening (composer prepare, keepers, MySQL-in-CI, Psalm key)  
9. Audit persistence + retention hooks  
10. Register gates/provider; fix 403 in tests  
11. Validation envelope alignment  
12. API docs and new feature tests

---

## Acceptance Criteria for Phase 4 Completion
- [x] Settings UI echoes configs, validates updates
- [x] RBAC middleware present, roles scaffolded; gates registered
- [x] Audit Trail listing present with stub fallback and DB mode
- [x] Evidence persisted with sha256 + versioning; retrieval streams with correct headers
- [x] Exports follow job/status pattern (stub)
- [x] Avatars validated (stub)
- [x] Docs/specs/tests updated for evidence/settings/audit
- [x] CI green
- [ ] RBAC enforcement + role-binding
- [ ] Exports job model and artifact generation
- [ ] Settings persistence + audited apply
