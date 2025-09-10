# Phase 4 — Core App Usable Detailed Task Breakdown

## Instruction Preamble
- Date 2025-09-09
- Phase 4
- Goal Decompose Phase 4 deliverables into detailed, sequential tasks to guide incremental scaffolding, enforcement, and persistence work.
- Constraints
  - No scope outside Charter/Backlog.
  - Each increment must pass CI guardrails.
  - Stubs advance to enforcement/persistence gradually.
  - Full traceability to Backlog IDs (CORE-003, CORE-004, CORE-006, CORE-007, CORE-008, CORE-010).

---

## Task Checklist by Feature Area

### 1. Settings UI (CORE-003 expansion)
- [x] Extend `Admin/SettingsController.php` with echo + validation stubs
- [x] Validation rules for RBAC, Audit, Evidence, Avatars
- [x] Extend SPA `/web/src/routes/admin/Settings.tsx`
- [x] Add unit tests (`SettingsValidationTest`)
- [x] Document payloads and error codes in PHASE-4-SPEC.md
- [x] Persist settings to DB with audited apply
- [x] Surface effective config via API

### 2. RBAC enforcement + roles (CORE-004)
- [x] Introduce `RbacMiddleware` and role defaults on routes
- [x] Scaffold roles endpoints (`/api/rbac/roles` GET/POST)
- [ ] Fine-grained policy matrix and UI
- [ ] Enforcement tests and negative cases
- [ ] Docs: role capabilities table and error taxonomy

### 3. Audit trail (CORE-006)
- [x] List endpoint stub with pagination params
- [ ] Migration finalized for `audit_events` (ULID PK, actor, action, entity, network, agent)
- [ ] Write-on-change hooks for settings apply and RBAC changes
- [ ] Tests for event shapes and filters
- [ ] Docs: event schema and categories

### 4. Evidence (CORE-007)
- [x] Persisted storage API (`/api/evidence` index/store/show)
- [ ] Size/type limits + virus scan hooks
- [ ] Download with signed URLs and audit log
- [ ] Tests for upload constraints and retrieval
- [ ] Docs: retention and chain-of-custody notes

### 5. Exports (CORE-008)
- [x] Model `App\Models\Export` with ULID, immutable timestamps, casts
- [x] Job `App\Jobs\GenerateExport` queued worker, progress simulation
- [x] Service `App\Services\Export\ExportService::enqueue()`
- [x] Controllers wire-in with feature gate `core.exports.enabled` and table check
- [x] Migration expanded: artifact metadata and error fields
- [x] Tests remain green with stub IDs when feature disabled
- [ ] CSV generator: build dataset, stream to temp, persist to storage, set artifact fields, mark completed
- [ ] JSON generator: same flow as CSV
- [ ] PDF generator: placeholder using simple renderer; full templating later
- [ ] Download endpoint: return 404 until artifact present, then stream/download with correct `Content-Type` and length
- [ ] Status polling tests and generator happy-path tests
- [ ] Docs: export types, params schema, error codes

### 6. Avatars (CORE-010)
- [x] Upload scaffold endpoint
- [ ] Storage, transformation, and RBAC checks
- [ ] Tests and docs

### 7. DevEx, QA, and Docs
- [x] CI workflows pass: PHPStan, Psalm, PHPUnit
- [x] Lint/format passes (Pint)
- [ ] Expand PHASE-4-SPEC with exports persistence and download contracts
- [ ] Update ROADMAP/BACKLOG statuses for CORE-008 partial completion
- [ ] SESSION-LOG entry recorded for this session

---

## Immediate Next Sprint (narrow)
1. Implement CSV generator end-to-end (storage write + status + download).
2. Add feature-flagged tests that toggle `core.exports.enabled=true` to exercise non-stub path.
3. Document export payloads and download behavior in PHASE-4-SPEC.md.
