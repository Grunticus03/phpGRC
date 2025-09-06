# FILE: docs/core/PHASE-4-SPEC.md
# Phase 4 — Core App Usable Spec

## Instruction Preamble
- **Date:** 2025-09-05
- **Phase:** 4
- **Goal:** Lock contracts, payloads, config keys, and stub migrations for Settings, RBAC, Audit, Evidence, Exports, Avatars.
- **Constraints:** Stubs only; no persistence; deterministic outputs; CI guardrails intact.

---
## Config Keys (defaults; echo-only this phase)

core.rbac.enabled: true  
core.rbac.roles: [Admin, Auditor, Risk Manager, User]  
core.audit.enabled: true  
core.audit.retention_days: 365  # min 1, max 730 (2 years)  
core.evidence.enabled: true  
core.evidence.max_mb: 25  
core.evidence.allowed_mime: [application/pdf, image/png, image/jpeg, text/plain]  
core.avatars.enabled: true  
core.avatars.size_px: 128  
core.avatars.format: webp  

Notes:
- Evidence max default 25 MB per Charter.  
- Avatars canonical size 128 px WEBP per Charter.  
- Audit retention capped at 2 years per Backlog.

---
## Error Taxonomy (Phase-4 additions)

This phase reuses global codes (e.g., VALIDATION_FAILED, UNAUTHENTICATED/UNAUTHORIZED, INTERNAL_ERROR) and adds scoped codes for clarity. See Phase-1/2 taxonomies for shared values.

- **Settings/RBAC:** RBAC_DISABLED, ROLE_NOT_FOUND, ROLE_NAME_INVALID
- **Audit:** AUDIT_NOT_ENABLED, AUDIT_RETENTION_INVALID
- **Evidence:** EVIDENCE_NOT_ENABLED, EVIDENCE_TOO_LARGE, EVIDENCE_MIME_NOT_ALLOWED
- **Exports:** EXPORT_TYPE_UNSUPPORTED, EXPORT_NOT_READY, EXPORT_NOT_FOUND
- **Avatars:** AVATAR_NOT_ENABLED, AVATAR_INVALID_IMAGE, AVATAR_UNSUPPORTED_FORMAT, AVATAR_TOO_LARGE

---
## Endpoints and Contracts

### Admin Settings
- `GET /api/admin/settings`
  - Response: `{ ok: true, config: { core: { rbac, audit, evidence, avatars } } }`
- `POST /api/admin/settings`
  - Request JSON (all fields optional; validate only, no persistence):
    ```json
    {
      "rbac": { "enabled": true, "roles": ["Admin","Auditor","Risk Manager","User"] },
      "audit": { "enabled": true, "retention_days": 365 },
      "evidence": { "enabled": true, "max_mb": 25, "allowed_mime": ["application/pdf","image/png","image/jpeg","text/plain"] },
      "avatars": { "enabled": true, "size_px": 128, "format": "webp" }
    }
    ```
  - Response: `{ ok: true, applied: false, note: "stub-only" }`
  - Errors: VALIDATION_FAILED, UNAUTHORIZED, INTERNAL_ERROR

### RBAC Roles
- `GET /api/rbac/roles`
  - Response: `{ ok: true, roles: ["Admin","Auditor","Risk Manager","User"] }`
- `POST /api/rbac/roles`
  - Request JSON: `{ "name": "..." }` (no-op)
  - Response: `{ ok: false, note: "stub-only" }`
  - Errors: ROLE_NAME_INVALID, ROLE_NOT_FOUND, UNAUTHORIZED

### Audit
- `GET /api/audit?limit=25&cursor=<opaque>`
  - Params: `limit` 1..100 (default 25), `cursor` opaque string
  - Response:
    ```json
    {
      "ok": true,
      "items": [
        {
          "occurred_at": "2025-09-05T12:00:00Z",
          "actor_id": 1,
          "action": "settings.update",
          "category": "SETTINGS",
          "entity_type": "core.config",
          "entity_id": "rbac",
          "ip": "203.0.113.10",
          "ua": "Mozilla/5.0",
          "meta": {}
        }
      ],
      "nextCursor": null
    }
    ```
  - Errors: AUDIT_NOT_ENABLED, UNAUTHORIZED

### Evidence
- `POST /api/evidence`  (multipart/form-data)
  - Fields: `file` (required), `owner_id` (optional)
  - Validation: size ≤ `core.evidence.max_mb`, mime ∈ `core.evidence.allowed_mime`
  - Response: `{ ok: false, note: "stub-only" }`
  - Errors: EVIDENCE_NOT_ENABLED, EVIDENCE_TOO_LARGE, EVIDENCE_MIME_NOT_ALLOWED

### Exports
- `POST /api/exports/{type}` where `{type} ∈ {csv,json,pdf}`
  - Query/body: `report` (string id), `params` (object) — validated, not persisted
  - Response: `{ ok: true, jobId: "exp_stub_0001" }`
  - Errors: EXPORT_TYPE_UNSUPPORTED
- `GET /api/exports/{id}/status`
  - Response: `{ ok: true, status: "pending", progress: 0 }`
- `GET /api/exports/{id}/download`
  - Always 404 stub: `{ ok: false, code: "EXPORT_NOT_READY" }`

### Avatars
- `POST /api/avatar`  (multipart/form-data)
  - Fields: `image` (required)
  - Validation: must be image; format = `core.avatars.format`; target size = `core.avatars.size_px` (processing deferred)
  - Response: `{ ok: false, note: "stub-only" }`
  - Errors: AVATAR_NOT_ENABLED, AVATAR_INVALID_IMAGE, AVATAR_UNSUPPORTED_FORMAT, AVATAR_TOO_LARGE

---
## Middleware

### RbacMiddleware (placeholder)
- Input: required role(s) set via route attribute or header.
- Behavior: if `core.rbac.enabled == false` → bypass.
- Errors: UNAUTHENTICATED, UNAUTHORIZED, RBAC_DISABLED.

---
## Models (placeholders)

### Role
- Fields: id, name (unique), created_at, updated_at  
- Notes: authority mapping deferred.

### AuditEvent
- Fields: id, occurred_at, actor_id (nullable), action, category, entity_type, entity_id, ip, ua, meta(json)  
- Notes: write path deferred.

### Evidence
- Fields: id, owner_id, filename, mime, size_bytes, sha256, created_at  
- Notes: storage deferred; sha256 placeholder only.

### Avatar
- Fields: id, user_id, path, mime, size_bytes, created_at, updated_at  
- Notes: processing deferred.

---
## Migrations (filenames reserved; columns indicative)
- `0000_00_00_000100_create_roles_table.php`  
  - id, name (string unique), timestamps
- `0000_00_00_000110_create_audit_events_table.php`  
  - id, occurred_at (datetime), actor_id (nullable fk users), action (string),
    category (string), entity_type (string), entity_id (string), ip (string), ua (string), meta (json), created_at
- `0000_00_00_000120_create_evidence_table.php`  
  - id, owner_id (fk users), filename, mime, size_bytes (bigint), sha256 (string 64), created_at
- `0000_00_00_000130_create_avatars_table.php`  
  - id, user_id (fk users), path, mime, size_bytes (bigint), timestamps

Policy: These coexist with prior installer/auth skeleton migrations. Not executed in Phase 4.

---
## Routes (append to `/api/routes/api.php`)
```php
// Settings
Route::get('/admin/settings', [Admin\SettingsController::class, 'index']);
Route::post('/admin/settings', [Admin\SettingsController::class, 'update']); // validate only

// RBAC
Route::get('/rbac/roles', [Rbac\RolesController::class, 'index']);
Route::post('/rbac/roles', [Rbac\RolesController::class, 'store']); // no-op

// Audit
Route::get('/audit', [Audit\AuditController::class, 'index']);

// Evidence
Route::post('/evidence', [Evidence\EvidenceController::class, 'store']); // validate-only

// Exports
Route::post('/exports/{type}', [Export\ExportController::class, 'create']); // csv|json|pdf
Route::get('/exports/{id}/status', [Export\StatusController::class, 'show']);
Route::get('/exports/{id}/download', [Export\ExportController::class, 'download']); // stub 404

// Avatars
Route::post('/avatar', [Avatar\AvatarController::class, 'store']); // validate-only
