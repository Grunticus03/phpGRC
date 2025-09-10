## Instruction Preamble
- Date 2025-09-08
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
- [x] Add `RbacMiddleware.php` (now enforcing with Sanctum PATs)
- [x] Add `RolesController.php` stub + SPA `/web/src/routes/admin/Roles.tsx`
- [x] Register gates in `AuthServiceProvider` (temporary allow-all)
- [x] Attach middleware to `/api/admin/*`, `/api/rbac/*`, `/api/audit`
- [x] Guard selection: `auth:sanctum` + `RbacMiddleware` when `core.rbac.require_auth=true`
- [x] DB role-binding via seeder; pivot validated
- [ ] Replace allow-all gates with fine-grained policies and assignments UI
- [x] Middleware tests updated

### 3. Audit Trail (CORE-007)
- [x] `AuditController.php` with strict param validation and DB-backed listing
- [x] `AuditEvent.php` model + migration
- [x] Persist audit events for evidence actions (upload/read/head) and settings updates
- [x] Retention purge job honoring `core.audit.retention_days`
- [x] Feature tests (`AuditApiTest`, `AuditControllerTest`)

### 4. Evidence Pipeline (CORE-006)
- [x] `EvidenceController.php` with validations
- [x] `StoreEvidenceRequest.php` (unified error envelope)
- [x] SPA `/web/src/routes/evidence/index.tsx`
- [x] Migration `...create_evidence_table.php` with sha256 + LONGBLOB
- [x] `Evidence.php` model
- [x] Persist bytes + sha256 + per-filename versioning
- [x] `GET/HEAD /api/evidence/{id}` with `ETag`, `Content-Length`, `nosniff`, attachment disposition
- [x] `GET /api/evidence?limit&cursor` opaque cursor pagination
- [x] Audit hooks for upload/read/head
- [x] Feature tests; docs to finalize
- [x] Document API in `/docs/api/EVIDENCE.md`

### 5. Exports (CORE-008 expansion)
- [x] Extend `ExportController.php` with `create` + `download` (stub)
- [x] `StatusController.php` (stub)
- [ ] SPA `/web/src/routes/exports/index.tsx`
- [ ] Migration `exports`
- [ ] Job model and artifact generation (CSV/JSON/PDF)
- [x] Feature tests (`ExportApiTest`)

### 6. Avatars (CORE-010)
- [x] `AvatarController.php` with validation stub
- [x] `StoreAvatarRequest.php`
- [ ] SPA `/web/src/routes/profile/Avatar.tsx`
- [ ] Migration `avatars` with unique user_id
- [ ] `Avatar.php` model
- [x] Feature tests

### 7. Settings Persistence (CORE-003 apply)
- [x] Implement `SettingsService::apply` with diffing and contract-key filtering
- [x] Fire `SettingsUpdated` event with changes + context
- [x] Listener `RecordSettingsUpdate` persists one audit row per apply (ULID ids)
- [x] Register `EventServiceProvider` and add to `bootstrap/app.php`
- [x] Feature tests for set/unset semantics and partial updates
- [x] CI green across PHPUnit/PHPStan/Psalm/Pint

---

## Cross-Cutting Tasks
- [x] Suppress guest redirect; global JSON 401 handler
- [x] Local-only `/api/dev/bootstrap` to mint tokens (dev env)
- [x] CI sqlite seeding for roles + pivot verification
- [x] Update PHASE-4-SPEC.md with final payloads and errors
- [x] CI guardrails: Pint / PHPStan / Psalm / PHPUnit on PRs
- [x] Composer bootstrap: `scripts/composer/app-prepare.php`

---

## Sequencing (Completed)
1. Settings echo+validate
2. RBAC middleware + route-level roles
3. Audit listing + validation
4. Evidence persistence + headers + audits
5. CI/dev bootstrap (tokens, keepers, sqlite seeding)
6. Error envelope alignment (JSON 401/403)
7. Spec & capabilities updates

---

## Acceptance Criteria for Phase 4 Completion
- [x] Settings UI echoes configs, validates updates
- [x] RBAC enforced via Sanctum + middleware; roles seeded and bound
- [x] Audit Trail listing with strict validation, retention enforced
- [x] Evidence persisted with versioning, retrieval, and audits
- [x] Exports follow job/status pattern (stub) with tests
- [x] Avatars validated (stub) with tests
- [x] Docs/specs/tests updated for settings/audit/evidence
- [x] CI green
- [ ] Fine-grained RBAC policies + UI role management
- [x] Settings persistence + audited apply
