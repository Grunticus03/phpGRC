# Phase 4 — Core App Usable Spec

## Instruction Preamble
- **Date:** 2025-09-07
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
core.avatars.format: webp  # format is locked to WEBP

Notes:
- Evidence default max 25 MB.
- Avatars canonical size 128 px, WEBP only.
- Audit retention capped at 2 years.

---

## Error Taxonomy (Phase-4 scope)

Shared: VALIDATION_FAILED, UNAUTHENTICATED, UNAUTHORIZED, INTERNAL_ERROR.

Settings/RBAC: RBAC_DISABLED, ROLE_NOT_FOUND, ROLE_NAME_INVALID  
Audit: AUDIT_NOT_ENABLED, AUDIT_RETENTION_INVALID  
Evidence: EVIDENCE_NOT_ENABLED, EVIDENCE_TOO_LARGE, EVIDENCE_MIME_NOT_ALLOWED  
Exports: EXPORT_TYPE_UNSUPPORTED, EXPORT_NOT_READY, EXPORT_NOT_FOUND  
Avatars: AVATAR_NOT_ENABLED, AVATAR_INVALID_IMAGE, AVATAR_UNSUPPORTED_FORMAT, AVATAR_TOO_LARGE

---

## Endpoints and Contracts

### Admin Settings
- `GET /api/admin/settings`
  - Response (200): `{ "ok": true, "config": { "core": { "rbac": {...}, "audit": {...}, "evidence": {...}, "avatars": {...} } } }`

- `POST /api/admin/settings`  (also accepts `PUT` for compatibility)
  - Request JSON (either shape accepted; server normalizes to top-level):
    - Spec shape:
      ```json
      {
        "rbac": { "enabled": true, "roles": ["Admin","Auditor","Risk Manager","User"] },
        "audit": { "enabled": true, "retention_days": 365 },
        "evidence": { "enabled": true, "max_mb": 25, "allowed_mime": ["application/pdf","image/png","image/jpeg","text/plain"] },
        "avatars": { "enabled": true, "size_px": 128, "format": "webp" }
      }
      ```
    - Legacy shape:
      ```json
      {
        "core": {
          "rbac": {...},
          "audit": {...},
          "evidence": {...},
          "avatars": {...}
        }
      }
      ```
  - Validation rules:
    - `rbac.enabled` boolean; `rbac.roles` array of strings 1..64 chars
    - `audit.retention_days` integer 1..730
    - `evidence.max_mb` integer ≥1; `evidence.allowed_mime` ⊆ default list
    - `avatars.size_px` must equal `128`; `avatars.format` must equal `"webp"`
  - Response (200): `{ "ok": true, "applied": false, "note": "stub-only", "accepted": { ...normalized... } }`

### RBAC Roles
- `GET /api/rbac/roles`
  - Response (200): `{ "ok": true, "roles": ["Admin","Auditor","Risk Manager","User"] }`
- `POST /api/rbac/roles`
  - Request: `{ "name": "..." }`
  - Response (202): `{ "ok": false, "note": "stub-only" }`

### Audit
- `GET /api/audit?limit=20&cursor=<opaque>`
  - `limit` 1..100 (default 20), `cursor` opaque
  - Response (200):
    ```json
    {
      "ok": true,
      "items": [
        {
          "id": "ae_0001",
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
      "nextCursor": null,
      "note": "stub-only"
    }
    ```
  - Errors: AUDIT_NOT_ENABLED, UNAUTHORIZED

### Evidence
- `POST /api/evidence`  (multipart/form-data)
  - Fields: `file` (required), `owner_id` (optional)
  - Validation: size ≤ `core.evidence.max_mb` MB; MIME ∈ `core.evidence.allowed_mime`
  - Response (202): `{ "ok": false, "code": "EVIDENCE_STUB", "note": "stub-only" }`
  - Errors: EVIDENCE_NOT_ENABLED, EVIDENCE_TOO_LARGE, EVIDENCE_MIME_NOT_ALLOWED

### Exports
- Preferred: `POST /api/exports/{type}` where `{type} ∈ {csv,json,pdf}`
  - Body: `{ "params": { ... } }` (optional)
  - Response (202): `{ "ok": true, "jobId": "exp_stub_0001", "type": "<type>", "params": { ... }, "note": "stub-only" }`
  - Errors: `EXPORT_TYPE_UNSUPPORTED` (422)

- Legacy (kept this phase): `POST /api/exports`
  - Body: `{ "type": "csv|json|pdf", "params": { ... } }`
  - Same response and errors as above.

- `GET /api/exports/{id}/status`
  - Response (200): `{ "ok": true, "status": "pending", "progress": 0, "id": "<id>" }`

- `GET /api/exports/{id}/download`
  - Response (404): `{ "ok": false, "code": "EXPORT_NOT_READY", "note": "stub-only" }`

### Avatars
- `POST /api/avatar`  (multipart/form-data)
  - Fields: `file` (required)
  - Validation: must be image; MIME must be `image/webp`; soft cap 2 MB; basic dimension sanity check
  - Response (202): `{ "ok": false, "code": "AVATAR_STUB", "note": "stub-only" }`
  - Errors: AVATAR_NOT_ENABLED, AVATAR_INVALID_IMAGE, AVATAR_UNSUPPORTED_FORMAT, AVATAR_TOO_LARGE

---

## Middleware

### RbacMiddleware (placeholder)
- Input: optional required role(s) via route metadata (future).
- Behavior: tags request with `rbac_enabled` boolean; never blocks in Phase 4.
- Errors: none in this phase.

---

## Models (placeholders)

Role: id, name (unique), timestamps  
AuditEvent: id, occurred_at, actor_id, action, category, entity_type, entity_id, ip, ua, meta(json)  
Evidence: id, owner_id, filename, mime, size_bytes, sha256, created_at  
Avatar: id, user_id, path, mime, size_bytes, timestamps

---

## Migrations (reserved; not executed in Phase 4)

- `0000_00_00_000100_create_roles_table.php`
- `0000_00_00_000110_create_audit_events_table.php`
- `0000_00_00_000120_create_evidence_table.php`
- `0000_00_00_000130_create_avatars_table.php`

---

## Routes (reference)

```php
// Settings
Route::get('/admin/settings', [Admin\SettingsController::class, 'index']);
Route::post('/admin/settings', [Admin\SettingsController::class, 'update']); // preferred
Route::put('/admin/settings',  [Admin\SettingsController::class, 'update']); // legacy

// RBAC
Route::get('/rbac/roles', [Rbac\RolesController::class, 'index']);
Route::post('/rbac/roles', [Rbac\RolesController::class, 'store']);

// Audit
Route::get('/audit', [Audit\AuditController::class, 'index']);

// Evidence
Route::post('/evidence', [Evidence\EvidenceController::class, 'store']);

// Exports
Route::post('/exports/{type}', [Export\ExportController::class, 'createType']); // preferred
Route::post('/exports',        [Export\ExportController::class, 'create']);     // legacy
Route::get('/exports/{id}/status',   [Export\StatusController::class, 'show']);
Route::get('/exports/{id}/download', [Export\ExportController::class, 'download']);

// Avatars
Route::post('/avatar', [Avatar\AvatarController::class, 'store']);
