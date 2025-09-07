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
- [ ] Extend SPA `/web/src/routes/admin/Settings.tsx`
- [x] Add unit tests (`SettingsValidationTest`)
- [x] Document payloads and error codes in PHASE-4-SPEC.md
- [ ] Document API in `/docs/api/SETTINGS.md` and `/docs/api/ERRORS.md`

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
- [ ] `AuditEvent.php` model + `...create_audit_events_table.php` migration
- [x] Define categories helper and retention config
- [ ] Persist audit events for evidence actions (upload/read/head) and settings updates
- [ ] Retention purge job honoring `core.audit.retention_days`
- [x] Feature tests (`AuditApiTest`, `AuditControllerTest`)

### 4. Evidence Pipeline (CORE-006)
- [x] `EvidenceController.php` with validations (Phase 4: validate-only stub)
- [x] `StoreEvidenceRequest.php` (unified error envelope)
- [ ] SPA `/web/src/routes/evidence/index.tsx`
- [x] Migration `...create_evidence_table.php` with sha256 + LONGBLOB
- [x] `Evidence.php` model
- [x] Persist bytes + sha256 + per-filename versioning
- [x] `GET/HEAD /api/evidence/{id}` with `ETag`, `Content-Length`, `nosniff`, attachment disposition
- [x] `GET /api/evidence?limit&cursor` opaque cursor pagination (+ created_at,id index)
- [x] Feature tests: upload, retrieve, ETag 304, pagination, 404, disabled path
- [x] Audit hooks for upload/read/head
- [ ] Document API in `/docs/api/EVIDENCE.md`

### 5. Exports (CORE-008 expansion)
- [x] Extend `ExportController.php` with `create` + `download` (stub)
- [x] `StatusController.php` (stub)
- [ ] SPA `/web/src/routes/exports/index.tsx`
- [ ] Migration stub `exports`
- [ ] Job model and artifact generation (CSV/JSON/PDF)
- [x] Feature tests (`ExportApiTest`)

### 6. Avatars (CORE-010)
- [x] `AvatarController.php` with validation stub
- [x] `StoreAvatarRequest.php`
- [ ] SPA `/web/src/routes/profile/Avatar.tsx`
- [ ] Migration stub `avatars` with unique user_id
- [ ] `Avatar.php` model
- [x] Feature tests

---

## Cross-Cutting Tasks
- [x] Update PHASE-4-SPEC.md with final payloads and errors
- [x] Update CAPABILITIES.md to mark Phase-4 features status
- [x] Add unit/feature tests for settings/audit controllers
- [x] Add unit/feature tests for evidence/exports/avatars
- [x] CI guardrails: Pint / PHPStan / Psalm / PHPUnit on PRs
- [x] Composer bootstrap: `scripts/composer/app-prepare.php`, `app:prepare` hook
- [x] Keepers: `bootstrap/cache`, `storage/**` `.gitignore` committed
- [x] Config templates: `api/.env.example`, `scripts/templates/shared-config.php`
- [x] CI DB service: MySQL container + provisioning
- [x] Docs: `/docs/DEV-SETUP.md`, `/docs/MAKE-TARGETS.md`
- [x] API docs updated for Settings/Audit in spec

---

## Sequencing (Completed)
1. Settings UI scaffolds (server echo+validate)
2. RBAC middleware + roles display
3. Audit Trail stub events + param validation
4. CI/dev bootstrap hardening (composer prepare, keepers, MySQL-in-CI, Psalm key)
5. Validation envelope alignment
6. Spec and capabilities updates

---

## Acceptance Criteria for Phase 4 Completion
- [x] Settings UI echoes configs, validates updates
- [x] RBAC middleware present, roles scaffolded; gates registered
- [x] Audit Trail listing present with stub fallback and strict param validation
- [x] Evidence validate-only stub implemented with tests
- [x] Exports follow job/status pattern (stub) with tests
- [x] Avatars validated (stub) with tests
- [x] Docs/specs/tests updated for settings/audit
- [x] CI green
- [ ] RBAC enforcement + role-binding
- [ ] Settings persistence + audited apply
