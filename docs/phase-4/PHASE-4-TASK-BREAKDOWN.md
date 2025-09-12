# Phase 4 — Core App Usable Detailed Task Breakdown

## Instruction Preamble
- Date 2025-09-12
- Phase 4
- Goal Decompose Phase 4 deliverables into detailed, sequential tasks to guide incremental scaffolding, enforcement, and persistence work.
- Constraints
  - No scope outside Charter/Backlog.
  - Each increment must pass CI guardrails.
  - Stubs advance to enforcement/persistence gradually.
  - Full traceability to Backlog IDs (CORE-003, CORE-004, CORE-006, CORE-007, CORE-008, CORE-010).
- Last updated: 2025-09-12

---

## Task Checklist by Feature Area

### 1. Settings UI (CORE-003 expansion)
- [x] Extend `Admin/SettingsController.php` with echo + validation stubs
- [x] Validation rules + unified 422 envelope
- [ ] Extend SPA `/web/src/routes/admin/Settings.tsx`
- [x] Add unit tests (`SettingsControllerValidationTest`)
- [x] Document payloads and error codes in `PHASE-4-SPEC.md`

### 2. Audit Trail (CORE-006)
- [x] Persisted `audit_events` table + model
- [x] Settings update listener writes audit rows
- [x] `AuditLogger` helper with typed attributes
- [x] Stub pagination + cursor semantics aligned with tests
- [x] Filters on list (category/action/date range + ids/ip) — persisted path
- [x] Retention job honoring `core.audit.retention_days` (CLI + scheduler clamp)

### 3. Evidence (CORE-007)
- [x] Persist evidence metadata + file store
- [x] Size/mime validation
- [x] SHA-256 compute + ETag headers
- [x] Pagination on list
- [ ] Filtering on list
- [ ] Hash verification on download

### 4. Exports (CORE-008)
- [x] Create job endpoints (preferred + legacy)
- [x] Status + download endpoints
- [x] Capability gate `core.exports.generate`
- [x] E2E tests green (CSV/JSON/PDF)
- [ ] Background queue path (beyond sync in tests)

### 5. Avatars (CORE-010)
- [x] Upload endpoint scaffold
- [ ] Transcode to WEBP in worker
- [ ] Serve resized variants

### 6. RBAC Enforcement + Catalog (CORE-004)
- [x] `RbacMiddleware` enforces when `core.rbac.enabled=true`
- [x] Role catalog endpoints (index/store) with slug IDs (`role_<slug>`)
- [x] User–role mapping endpoints (show/replace/attach/detach)
- [x] DB-backed checks via `User::hasAnyRole(...)`
- [x] CI tests for enforcement and endpoints
- [ ] UI for role management (admin route)
- [ ] Fine-grained policies (capability-level hooks)

### 7. RBAC Audit
- [x] Log canonical `rbac.role.created` and `rbac.user_role.{attached,detached,replaced}`
- [x] Write legacy aliases `role.{attach,detach,replace}` in parallel
- [x] Add audit list filters for category `RBAC`
- [ ] Export audit events as CSV

---

## Session/Quality Gates
- [x] Psalm/PHPStan/Pint clean
- [x] PHPUnit green in CI
- [x] Contracts frozen in `PHASE-4-SPEC.md` for delivered areas
- [ ] UX pass on admin screens (Phase 5)

---

## Immediate Next Steps
1. Evidence: add download hash verification and list filters.
2. Begin role management UI under `/admin/rbac` (read/attach/detach/replace).
3. Add background queue path for exports in non-test envs.
4. Add CSV export for audit events.
