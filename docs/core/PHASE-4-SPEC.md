# Phase 4 — Core App Usable Spec

## Instruction Preamble
- **Date:** 2025-09-05
- **Phase:** 4
- **Goal:** Lock contracts for settings, RBAC, audit, evidence, exports, avatars; add schemas and error codes; reserve migrations; define test plan.
- **Constraints:** Stub-only; no persistence; deterministic outputs; CI must stay green.

---

## Config Keys (echo-only defaults this phase)

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

## Authorization model (Phase-4 stubs)

### Gates (AuthServiceProvider)
- `core.settings.manage` → stub returns `true`
- `core.evidence.manage` → stub returns `true`
- `core.audit.view` → stub returns `true`

### Policies (skeletons)
- `SettingsPolicy` → `view`, `update` allow `true`
- `EvidencePolicy` → `create`, `view` allow `true`
- `AuditPolicy` → `view` allows `true`

> Enforcement is deferred. These keep endpoints inert while wiring is validated.

Controller usage targets (illustrative):
```
// SettingsController
$this->authorize('core.settings.manage');

// EvidenceController
$this->authorize('core.evidence.manage');

// AuditController
$this->authorize('core.audit.view');
```

---

## JSON Schemas (illustrative; enforcement deferred)

### Settings: POST /api/admin/settings
Request:
```
{
  "core": {
    "rbac": { "enabled": true },
    "audit": { "enabled": true, "retention_days": 365 },
    "evidence": { "enabled": true, "max_mb": 25, "allowed_mime": ["image/png"] },
    "avatars": { "enabled": true, "size_px": 128, "format": "webp" }
  }
}
```
Response (stub):
```
{ "ok": true, "applied": false, "note": "stub-only" }
```
Errors: UNAUTHORIZED, VALIDATION_FAILED, INTERNAL_ERROR.

### RBAC Roles
GET /api/rbac/roles →  
```
{ "ok": true, "roles": ["Admin","Auditor","Risk Manager","User"] }
```  
POST /api/rbac/roles (stub) →  
```
{ "ok": false, "note": "stub-only" }
```  
Errors: ROLE_NOT_FOUND, RBAC_DISABLED, UNAUTHORIZED, INTERNAL_ERROR.

### Audit
GET /api/audit →  
```
{ "ok": true, "items": [], "nextCursor": null }
```  
Errors: AUDIT_NOT_ENABLED, UNAUTHORIZED.

### Evidence
POST /api/evidence (multipart/form-data; stub validates only)  
Response:  
```
{ "ok": false, "note": "stub-only" }
```  
Errors: EVIDENCE_NOT_ENABLED, EVIDENCE_TOO_LARGE, EVIDENCE_INVALID_MIME, UNAUTHORIZED, INTERNAL_ERROR.

### Exports
POST /api/exports/{type} (type ∈ csv|json|pdf) →  
```
{ "ok": true, "jobId": "exp_stub_0001" }
```  
GET /api/exports/{id}/status →  
```
{ "ok": true, "status": "pending" }
```  
Errors: EXPORT_TYPE_UNSUPPORTED, EXPORT_NOT_READY, INTERNAL_ERROR.

### Avatars
POST /api/avatar (multipart/form-data; `image`) →  
```
{ "ok": false, "note":"stub-only" }
```
Errors: AVATAR_INVALID, AVATAR_TOO_LARGE, INTERNAL_ERROR.

---

## Error Taxonomy (consolidated)
UNAUTHENTICATED, UNAUTHORIZED, RBAC_DISABLED, ROLE_NOT_FOUND,  
AUDIT_NOT_ENABLED,  
EVIDENCE_NOT_ENABLED, EVIDENCE_TOO_LARGE, EVIDENCE_INVALID_MIME,  
EXPORT_TYPE_UNSUPPORTED, EXPORT_NOT_READY,  
AVATAR_INVALID, AVATAR_TOO_LARGE,  
VALIDATION_FAILED, INTERNAL_ERROR.

---

## Models (placeholders)
Role(id, name unique, timestamps)  
AuditEvent(id, occurred_at, actor_id?, action, entity_type, entity_id, ip, ua, meta json, created_at)  
Evidence(id, owner_id, filename, mime, size_bytes, sha256, created_at)  
Avatar(id, user_id, path, mime, size_bytes, timestamps)

---

## Migrations (reserved filenames; no execution this phase)
0000_00_00_000100_create_roles_table.php  
0000_00_00_000110_create_audit_events_table.php  
0000_00_00_000120_create_evidence_table.php  
0000_00_00_000130_create_avatars_table.php

---

## Middleware
RbacMiddleware (no-op when `core.rbac.enabled=false`); will enforce in later increment.

---

## Routes (append-only; already scaffolded)

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

---

## Test Plan (Phase-4 stubs)
- Feature tests exist but are marked `skipped` to keep CI green until Laravel app wiring lands.
- Unit tests added for gate/policy presence; also `skipped` to avoid framework bootstrap.

---

## Acceptance Criteria
- [x] Schemas and errors defined for all endpoints.
- [x] Reserved migration names documented.
- [x] Policy bindings and gates documented.
- [x] Test scaffolds added and skipped.
- [x] CI remains green on stubs.
