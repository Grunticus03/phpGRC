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
- [x] Validation rules + unified 422 envelope
- [ ] Extend SPA `/web/src/routes/admin/Settings.tsx`
- [x] Add unit tests (`SettingsControllerValidationTest`)
- [x] Document payloads and error codes in `PHASE-4-SPEC.md`

### 2. RBAC (CORE-004) — scaffolding → enforcement
- [x] `RbacMiddleware` conditional enforcement (enabled + roles → enforce; else passthrough)
- [x] `AuthServiceProvider` permissive gates for Phase 4 tests
- [x] `StoreRoleRequest` (min 2..64, duplicate check vs config)
- [x] `Rbac/RolesController@index,store` stubs with 202 echo
- [x] Feature tests for roles endpoint (`RolesEndpointTest`)
- [x] Route role defaults applied (`/admin/*`, `/exports/*`, `/audit`)
- [x] Admin Roles UI stub wired at `/admin/roles`
- [ ] Role persistence (migrations, pivot, seeder, DB-backed `store`)
- [ ] Fine-grained policies (Settings, Audit, Exports) + tests
- [ ] Role management UI (create/delete) with persistence

### 3. Exports (CORE-008) — E2E
- [x] Capability gate `core.exports.generate` at route/middleware
- [x] RBAC tests (`ExportsRbacTest`)
- [x] Job generation + artifact write for CSV/JSON/PDF
- [x] Download endpoint with correct headers
- [x] Legacy stub responses remain deterministic for tests
- [ ] Additional generators if specified (none pending)

### 4. Audit Trail (CORE-006)
- [x] Persist `audit_events` with ULIDs
- [x] Evidence actions emit audit
- [ ] Basic audit viewer route in SPA (read-only)

### 5. Evidence Pipeline (CORE-007)
- [x] Persist evidence with SHA-256 + pagination + HEAD/304
- [x] Validation + tests
- [ ] Viewer/download UI stubs in SPA

### 6. Avatars (CORE-010)
- [x] Store endpoint + validation (WEBP only)
- [ ] Storage backend + retrieval

### 7. Web SPA + Frontend CI
- [x] Convert `web/index.html` to SPA root
- [x] Add Vite + TypeScript scaffold
- [x] Add router, layout, and top nav
- [x] Wire Roles page (`/#/admin/roles`)
- [x] Add Vitest smoke test
- [x] Add `web-ci` GitHub Action with conditional Node cache
- [ ] Add Settings page and link
- [ ] Add Audit/Evidence viewer stubs

---

## Current Risks / Mitigations
- SPA structure introduced mid-phase → guard with minimal scope and CI typecheck/tests. Mitigation: keep UI read-only and stub-only.
- RBAC enforcement timing → keep gates permissive until policies tested. Mitigation: feature tests will drive enablement.

---

## Next Increments
1) Role persistence: migrations (`roles`, `role_user`), seeder alignment, DB-backed `RolesController@store`, feature tests.  
2) Fine-grained policies: Export/Settings/Audit policies + `$this->authorize(...)` where appropriate; tests.  
3) SPA expansion: Admin landing + Settings page; Audit/Evidence read-only views.

---

## Definition of Done (Phase 4)
- All CORE-003/004/006/007/008/010 acceptance criteria met per Charter and Spec.
- API and SPA routes covered by feature tests or smoke tests.
- CI guardrails green across PHP and Web pipelines.
