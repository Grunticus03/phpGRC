# FILE: /docs/PHASE-4-TASK-BREAKDOWN.md

# Phase 4 — Core App Usable Detailed Task Breakdown

## Instruction Preamble
- Date 2025-09-10
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
- [x] Persistence apply + audit of settings changes

### 2. RBAC (CORE-004)
- [x] Middleware scaffold with route `->defaults('roles', [...])`
- [x] Sanctum PAT wiring; 401/403 contracts
- [x] Enforce RBAC on Exports endpoints; require `Admin` for create; `Admin`/`Auditor` for status/download
- [x] Capability gate `core.exports.generate` checked in middleware
- [ ] Fine-grained policies and role management UI

### 3. Audit (CORE-006)
- [x] Audit listing endpoint with pagination
- [x] Persistence: `audit_events` table, write path, retention enforcement
- [x] Tests: shape + retention

### 4. Evidence (CORE-007)
- [x] Multipart upload validate size/mime by config
- [x] Persistence: storage with sha256 + metadata
- [x] Listing + retrieval endpoints
- [x] Tests: upload + fetch

### 5. Exports (CORE-008)
- [x] Migration: `exports` table with lifecycle + artifact fields
- [x] Model/Service + `GenerateExport` job
- [x] Implement CSV artifact (RFC4180)
- [x] Implement JSON artifact (UTF-8)
- [x] Implement minimal PDF artifact
- [x] Status endpoint returns `pending|running|completed|failed` with progress
- [x] Download streams artifact with correct content type + filename
- [x] Stub path preserved when persistence disabled
- [x] E2E tests: CSV/JSON/PDF + stub behavior
- [ ] Tests: RBAC for exports endpoints (authorized vs unauthorized)

### 6. Avatars (CORE-010)
- [x] Upload + validate, normalized to WEBP
- [x] Config documented

### 7. DevEx, QA, and Docs
- [x] CI workflows pass: PHPStan, Psalm, PHPUnit
- [x] Lint/format passes (Pint)
- [x] Expand PHASE-4-SPEC with exports persistence and download contracts
- [x] Update ROADMAP/BACKLOG statuses for CORE-008 completion
- [x] SESSION-LOG entry recorded for this session

---

## Immediate Next Sprint
1. RBAC: add fine-grained policies and tests for exports endpoints.
2. Role management UI scaffolding.
3. Prep Phase 5: wire OpenAPI and Spectral in CI.
