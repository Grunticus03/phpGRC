# @phpgrc:/docs/core/PHASE-4-SPEC.md
# Phase 4 — Core App Usable Spec

## Instruction Preamble
- **Date:** 2025-09-05
- **Phase:** 4
- **Goal:** Define contracts, payloads, config keys, and stub migrations for settings, RBAC, audit, evidence, exports, avatars.
- **Constraints:** Stubs only; no persistence; deterministic outputs.

---

## Config Keys (defaults; echo-only this phase)

core.rbac.enabled: true  
core.rbac.roles: [Admin, Auditor, Risk Manager, User]  
core.audit.enabled: true  
core.audit.retention_days: 365  
core.evidence.enabled: true  
core.evidence.max_mb: 25  
core.evidence.allowed_mime: [application/pdf, image/png, image/jpeg, text/plain]  
core.avatars.enabled: true  
core.avatars.size_px: 128  
core.avatars.format: webp  

---

## Models (placeholders)

### Role
- Fields: id, name (unique), created_at, updated_at  
- Notes: authority mapping deferred until RBAC enforcement

### AuditEvent
- Fields: id, occurred_at, actor_id (nullable), action, entity_type, entity_id, ip, ua, meta(json)  
- Notes: write path deferred; shape only

### Evidence
- Fields: id, owner_id, filename, mime, size_bytes, sha256, created_at  
- Notes: storage deferred; sha256 placeholder only

### Avatar
- Fields: id, user_id, path, mime, size_bytes, created_at, updated_at  
- Notes: processing deferred

---

## Migrations (filenames reserved; columns indicative)

- `0000_00_00_000100_create_roles_table.php`  
  - id, name (string unique), timestamps  

- `0000_00_00_000110_create_audit_events_table.php`  
  - id, occurred_at (datetime), actor_id (nullable fk users), action (string)  
  - entity_type (string), entity_id (string), ip (string), ua (string), meta (json), created_at  

- `0000_00_00_000120_create_evidence_table.php`  
  - id, owner_id (fk users), filename, mime, size_bytes (bigint), sha256 (string 64), created_at  

- `0000_00_00_000130_create_avatars_table.php`  
  - id, user_id (fk users), path, mime, size_bytes (bigint), timestamps  

> Policy: These coexist with prior installer and auth skeleton migrations. Not executed in Phase 4.

---

## Middleware

### RbacMiddleware (placeholder)
- Input: required role(s) header or route attribute
- Behavior: if `core.rbac.enabled=false` → bypass with no-op
- Errors: UNAUTHENTICATED, UNAUTHORIZED, RBAC_DISABLED (not enforced this phase)

---

## Endpoints and Contracts

### Admin Settings
- `GET /api/admin/settings` → `{ ok:true, config:{ core:{...} } }`
- `POST /api/admin/settings` → `{ ok:true, applied:false, note:"stub-only" }`
- Errors: UNAUTHORIZED, INTERNAL_ERROR

### RBAC Roles
- `GET /api/rbac/roles` → `{ ok:true, roles:["Admin","Auditor","Risk Manager","User"] }`
- `POST /api/rbac/roles` → `{ ok:false, note:"stub-only" }`
- Errors: ROLE_NOT_FOUND, INTERNAL_ERROR

### Audit
- `GET /api/audit` → `{ ok:true, items:[], nextCursor:null }`
- Errors: AUDIT_NOT_ENABLED

### Evidence
- `POST /api/evidence` → `{ ok:false, note:"stub-only" }`
- Errors: EVIDENCE_TOO_LARGE, EVIDENCE_NOT_ENABLED, INTERNAL_ERROR

### Exports
- `POST /api/exports/:type` → `{ ok:true, jobId:"exp_stub_0001" }`
- `GET /api/exports/:id/status` → `{ ok:true, status:"pending" }`
- Errors: EXPORT_NOT_READY

### Avatars
- `POST /api/avatar` → `{ ok:false, note:"stub-only" }`
- Errors: AVATAR_INVALID, AVATAR_TOO_LARGE

---

## Routes (to append to `/api/routes/api.php`)

```
// Settings
Route::get('/admin/settings', [Admin\SettingsController::class, 'index']);
Route::post('/admin/settings', [Admin\SettingsController::class, 'update']); // no-op

// RBAC
Route::get('/rbac/roles', [Rbac\RolesController::class, 'index']);
Route::post('/rbac/roles', [Rbac\RolesController::class, 'store']); // no-op

// Audit
Route::get('/audit', [Audit\AuditController::class, 'index']);

// Evidence
Route::post('/evidence', [Evidence\EvidenceController::class, 'store']); // no-op

// Exports
Route::post('/exports/{type}', [Export\ExportController::class, 'create']); // csv|json|pdf
Route::get('/exports/{id}/status', [Export\ExportController::class, 'status']);

// Avatars
Route::post('/avatar', [Avatar\AvatarController::class, 'store']); // no-op
```

## Acceptance Criteria
- Config keys available and echoed by settings endpoint
- Controllers, middleware, models, and routes exist with TODOs
- Migration filenames reserved, not executed
- CI green

## Risks
- Scope creep → keep endpoints stub-only
- Schema drift → centralize keys under core.*
- File handling → no storage this phase

## References
- ROADMAP Phase 4
- BACKLOG: CORE-003, CORE-004, CORE-006, CORE-007, CORE-008, CORE-010