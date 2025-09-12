# Phase 4 — Core App Usable Spec

## Instruction Preamble
- **Date:** 2025-09-11
- **Phase:** 4
- **Goal:** Lock contracts, payloads, config keys, and persistence behaviors for Settings, RBAC, Audit, Evidence, Exports, Avatars.
- **Constraints:** CI guardrails intact; deterministic outputs; stub-path preserved when persistence disabled via config.

---

## Config Keys (defaults; Phase-4)

core.rbac.enabled: true  
core.rbac.require_auth: false  
core.rbac.mode: stub  
core.rbac.persistence: false  
core.rbac.roles: [Admin, Auditor, Risk Manager, User]

core.audit.enabled: true  
core.audit.retention_days: 365

core.evidence.enabled: true  
core.evidence.max_mb: 25  
core.evidence.allowed_mime: [application/pdf, image/png, image/jpeg, text/plain]

core.avatars.enabled: true  
core.avatars.size_px: 128  
core.avatars.format: webp

core.exports.enabled: true  
core.exports.disk: <FILESYSTEM_DISK|local>  
core.exports.dir: exports

core.capabilities.core.exports.generate: true

Notes:
- RBAC persistence path is active when `core.rbac.mode=persist` **or** `core.rbac.persistence=true`.
- Evidence default max 25 MB.
- Avatars canonical size 128 px, WEBP only.
- Audit retention capped at 2 years.
- Exports write artifacts under configured disk/dir; queue `sync` in tests.

---

## Error Taxonomy (Phase-4 scope)

Shared: VALIDATION_FAILED, UNAUTHENTICATED, UNAUTHORIZED, INTERNAL_ERROR.

Settings/RBAC: RBAC_DISABLED, ROLE_NOT_FOUND, ROLE_NAME_INVALID  
Audit: AUDIT_NOT_ENABLED, AUDIT_RETENTION_INVALID  
Evidence: EVIDENCE_NOT_ENABLED, EVIDENCE_TOO_LARGE, EVIDENCE_MIME_NOT_ALLOWED  
Exports: EXPORT_TYPE_UNSUPPORTED, EXPORT_NOT_READY, EXPORT_NOT_FOUND, EXPORT_FAILED, EXPORT_ARTIFACT_MISSING  
Avatars: AVATAR_NOT_ENABLED, AVATAR_INVALID_IMAGE, AVATAR_UNSUPPORTED_FORMAT, AVATAR_TOO_LARGE

---

## Endpoints and Contracts

### RBAC — Role Catalog
- `GET /api/rbac/roles`
  - Persistence disabled: returns configured names array.
    ~~~json
    { "ok": true, "roles": ["Admin","Auditor","Risk Manager","User"] }
    ~~~
  - Persistence enabled: returns names from DB, ordered by name.
    ~~~json
    { "ok": true, "roles": ["Admin","Auditor","Risk Manager","User"] }
    ~~~
- `POST /api/rbac/roles` (persist path only)
  - Request:
    ~~~json
    { "name": "Compliance Lead" }
    ~~~
  - Response 201:
    ~~~json
    { "ok": true, "role": { "id": "role_compliance_lead", "name": "Compliance Lead" } }
    ~~~
  - Collision rule:
    - If `role_compliance_lead` exists, next becomes `role_compliance_lead_1`, then `_2`, etc.
  - Stub path (persistence off):
    ~~~json
    { "ok": false, "note": "stub-only", "accepted": { "name": "..." } }
    ~~~

- **Role ID Contract**
  - Human-readable slug ID shown in UI/API.
  - Format: `role_<slug>`, lowercase ASCII, `_` separator.
  - Collision suffix: `_<N>` where `N` starts at 1.

### RBAC — User Role Mapping
- `GET /api/rbac/users/{userId}/roles`
  ~~~json
  { "ok": true, "user": { "id": 123, "name": "...", "email": "..." }, "roles": ["Admin","Auditor"] }
  ~~~
- `PUT /api/rbac/users/{userId}/roles` — replace set
  - Request:
    ~~~json
    { "roles": ["Auditor","Risk Manager"] }
    ~~~
  - Response 200 mirrors `GET`.
  - Errors: `ROLE_NOT_FOUND` for unknown names.
- `POST /api/rbac/users/{userId}/roles/{name}` — attach by role name  
- `DELETE /api/rbac/users/{userId}/roles/{name}` — detach by role name
- Enforcement:
  - Route-level `roles` defaults enforce access via `RbacMiddleware`.
  - When `core.rbac.require_auth=true`, Sanctum auth required first.

### Admin Settings
- `GET /api/admin/settings`
  - Response:
    ~~~json
    { "ok": true, "config": { "core": { "rbac": {...}, "audit": {...}, "evidence": {...}, "avatars": {...} } } }
    ~~~
- `POST /api/admin/settings`  (also accepts `PUT` and `PATCH`)
  - Request JSON (either shape accepted; server normalizes to spec):
    - Spec shape:
      ~~~json
      {
        "rbac": { "enabled": true, "roles": ["Admin","Auditor","Risk Manager","User"] },
        "audit": { "enabled": true, "retention_days": 365 },
        "evidence": { "enabled": true, "max_mb": 25, "allowed_mime": ["application/pdf","image/png","image/jpeg","text/plain"] },
        "avatars": { "enabled": true, "size_px": 128, "format": "webp" }
      }
      ~~~
    - Legacy shape:
      ~~~json
      {
        "core": {
          "rbac": { "enabled": true, "roles": ["Admin","Auditor","Risk Manager","User"] },
          "audit": { "enabled": true, "retention_days": 365 },
          "evidence": { "enabled": true, "max_mb": 25, "allowed_mime": ["application/pdf","image/png","image/jpeg","text/plain"] },
          "avatars": { "enabled": true, "size_px": 128, "format": "webp" }
        }
      }
      ~~~
  - Response 200 mirrors `GET` shape.

### Evidence
- `GET /api/evidence` — list
- `POST /api/evidence` — create; stores file, sha256, metadata; validates size/mime
- `GET /api/evidence/{id}` — fetch metadata or file as applicable

### Audit
- `GET /api/audit` — list events with pagination; retention applies

### Exports (CORE-008)
- RBAC:
  - Create: roles `["Admin"]` AND capability `core.exports.generate` must be enabled.
  - Status/Download: roles `["Admin","Auditor"]`.
- Types: `csv`, `json`, `pdf`.
- Creation:
  - `POST /api/exports/{type}` (preferred) with body `{ "params": {} }`
  - `POST /api/exports` (legacy) with body `{ "type": "csv|json|pdf", "params": {} }`
  - Responses:
    - Persistence disabled (stub path):
      ~~~json
      { "ok": true, "jobId": "exp_stub_0001", "type": "<type>", "params": {}, "note": "stub-only" }
      ~~~
    - Persistence enabled:
      ~~~json
      { "ok": true, "jobId": "<ULID>", "type": "<type>", "params": {} }
      ~~~
    - Unsupported type:
      ~~~json
      { "ok": false, "code": "EXPORT_TYPE_UNSUPPORTED", "note": "stub-only" }
      ~~~
- Status:
  - `GET /api/exports/{jobId}/status`
  - Responses:
    - Persisted job:
      ~~~json
      { "ok": true, "status": "pending|running|completed|failed", "progress": 0, "jobId": "<ULID>" }
      ~~~
    - Stub id or persistence disabled:
      ~~~json
      { "ok": true, "status": "pending", "progress": 0, "jobId": "<id>", "note": "stub-only" }
      ~~~
    - Not found:
      ~~~json
      { "ok": false, "code": "EXPORT_NOT_FOUND" }
      ~~~
- Download:
  - `GET /api/exports/{jobId}/download`
  - Responses:
    - Not ready:
      ~~~json
      { "ok": false, "code": "EXPORT_NOT_READY", "jobId": "<id>" }
      ~~~
    - Failed:
      ~~~json
      { "ok": false, "code": "EXPORT_FAILED", "errorCode": "...", "errorNote": "..." }
      ~~~
    - Missing artifact after completion:
      ~~~json
      { "ok": false, "code": "EXPORT_ARTIFACT_MISSING" }
      ~~~
    - Completed: file download with headers:
      - CSV: `Content-Type: text/csv; charset=UTF-8`, filename `export-<id>.csv`
      - JSON: `Content-Type: application/json; charset=UTF-8`, filename `export-<id>.json`
      - PDF: `Content-Type: application/pdf`, filename `export-<id>.pdf`
- Artifact metadata (model fields):
  - `artifact_disk`, `artifact_path`, `artifact_mime`, `artifact_size`, `artifact_sha256`
  - `status`: `pending|running|completed|failed`; `progress` `0..100`
  - `completed_at`, `failed_at`, `error_code`, `error_note`

### Avatars
- `POST /api/avatar` — upload avatar; WEBP target format

---

## Routes Excerpt (Laravel)

~~~php
// Build RBAC stack
$rbacStack = [RbacMiddleware::class];
if (config('core.rbac.require_auth', false)) {
    array_unshift($rbacStack, 'auth:sanctum');
}

// Admin Settings
Route::prefix('/admin')->middleware($rbacStack)->group(function () {
    Route::match(['GET','HEAD'], '/settings', [SettingsController::class, 'index'])->defaults('roles', ['Admin']);
    Route::post('/settings', [SettingsController::class, 'update'])->defaults('roles', ['Admin']);
    Route::put('/settings',  [SettingsController::class, 'update'])->defaults('roles', ['Admin']);
    Route::patch('/settings',[SettingsController::class, 'update'])->defaults('roles', ['Admin']);
});

// RBAC Roles (persist path)
Route::prefix('/rbac')->middleware($rbacStack)->group(function () {
    Route::match(['GET','HEAD'], '/roles', [RolesController::class, 'index'])->defaults('roles', ['Admin']);
    Route::post('/roles', [RolesController::class, 'store'])->defaults('roles', ['Admin']);
});

// Exports with RBAC + capability
Route::prefix('/exports')->middleware($rbacStack)->group(function () {
    Route::post('/{type}', [ExportController::class, 'createType'])->defaults('roles', ['Admin'])->defaults('capability', 'core.exports.generate');
    Route::post('/',       [ExportController::class, 'create'])->defaults('roles', ['Admin'])->defaults('capability', 'core.exports.generate');
    Route::get('/{id}/status',   [StatusController::class, 'show'])->defaults('roles', ['Admin','Auditor']);
    Route::get('/{id}/download', [ExportController::class, 'download'])->defaults('roles', ['Admin','Auditor']);
});
~~~

---

## Persistence & Queueing Notes
- When `core.exports.enabled=false` or `exports` table is absent, controllers return stub responses and never write files.
- Tests set `queue.default=sync` to run `GenerateExport` immediately.
- CSV uses RFC4180 quoting; JSON is UTF-8 without escaping slashes; PDF is a minimal valid single-page document.
