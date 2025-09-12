# Phase 4 — Core App Usable Spec

## Instruction Preamble
- **Date:** 2025-09-12
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

# Setup Wizard (bugfix scope)
core.setup.enabled: true  
core.setup.shared_config_path: /opt/phpgrc/shared/config.php  
core.setup.allow_commands: false

Notes:
- Enforcement: when `core.rbac.enabled=true`, route `roles` are enforced. `mode`/`persistence` do not bypass enforcement. `require_auth=true` yields 401 on no auth; otherwise anonymous passthrough occurs before role checks.
- Persistence path for RBAC catalog and assignments is active when `core.rbac.mode=persist` **or** `core.rbac.persistence=true`.
- Evidence default max 25 MB.
- Avatars canonical size 128 px, WEBP only.
- Audit retention capped at 2 years.
- Exports write artifacts under configured disk/dir.
- **Queue:** tests force `queue.default=sync`; production may use any Laravel-supported queue.
- **Setup Wizard:** Only database connection config is written to disk at `core.setup.shared_config_path`. All other settings persist in DB. Redirect-to-setup behavior outside this spec.

---

## Error Taxonomy (Phase-4 scope)

Shared: VALIDATION_FAILED, UNAUTHENTICATED, UNAUTHORIZED, INTERNAL_ERROR.

Settings/RBAC: RBAC_DISABLED, ROLE_NOT_FOUND, ROLE_NAME_INVALID  
Audit: AUDIT_NOT_ENABLED, AUDIT_RETENTION_INVALID  
Evidence: EVIDENCE_NOT_ENABLED, EVIDENCE_TOO_LARGE, EVIDENCE_MIME_NOT_ALLOWED, **EVIDENCE_HASH_MISMATCH**  
Exports: EXPORT_TYPE_UNSUPPORTED, EXPORT_NOT_READY, EXPORT_NOT_FOUND, EXPORT_FAILED, EXPORT_ARTIFACT_MISSING  
Avatars: AVATAR_NOT_ENABLED, AVATAR_INVALID_IMAGE, AVATAR_UNSUPPORTED_FORMAT, AVATAR_TOO_LARGE  
Setup: SETUP_ALREADY_COMPLETED, SETUP_STEP_DISABLED, DB_CONFIG_INVALID, DB_WRITE_FAILED, APP_KEY_EXISTS, SCHEMA_INIT_FAILED, ADMIN_EXISTS, SMTP_INVALID, IDP_UNSUPPORTED, BRANDING_INVALID

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

**Role ID Contract**
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
  - Route-level `roles` defaults are enforced by `RbacMiddleware`.
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
- `GET /api/evidence` — list with filters and cursor pagination.
  - Query filters:
    - `owner_id` integer
    - `filename` substring match
    - `mime` exact match or family like `image/*`
    - `sha256` exact
    - `sha256_prefix` prefix match
    - `version_from` integer
    - `version_to` integer
    - `created_from`, `created_to` ISO8601 or any `Carbon::parse`-able date
    - `order` ∈ `asc|desc` (default `desc`)
    - `limit` 1..100 (default 20)
    - `cursor` `base64("Y-m-d H:i:s|<id>")`
  - Response:
    ~~~json
    { "ok": true, "filters": {...}, "data": [ { "id":"ev_...","owner_id":1,"filename":"...", "mime":"...", "size_bytes":123, "sha256":"...", "version":1, "created_at":"2025-09-12T00:00:00Z" } ], "next_cursor": "..." }
    ~~~
- `POST /api/evidence` — create; stores file, sha256, metadata; validates size/mime.
- `GET|HEAD /api/evidence/{id}` — download with headers:
  - `ETag: "<sha256>"`
  - `X-Content-Type-Options: nosniff`
  - `X-Checksum-SHA256: <sha256>`
  - Optional `?sha256=<hex>` enforces hash match and returns `412 EVIDENCE_HASH_MISMATCH` when mismatched.
  - Honors `If-None-Match` for conditional GET.

### Audit
- `GET /api/audit` — list events with pagination.
  - Filters (query string):
    - `category` ∈ `["AUTH","SETTINGS","RBAC","EVIDENCE","EXPORT","USER","SYSTEM"]`
    - `action` string ≤191
    - `occurred_from`, `occurred_to` (ISO8601 or any `Carbon::parse`-able date; server coerces to UTC)
    - `actor_id` integer
    - `entity_type` string ≤128
    - `entity_id` string ≤191
    - `ip` valid IP
    - `order` ∈ `asc|desc` (default `desc`)
    - `limit` 1..100. Default 2 on first page, 1 on cursor-only requests.
    - `cursor` pagination cursor. Aliases: `cursor`, `nextCursor`, `page[cursor]`. Format `base64(ts|id|limit|emittedCount)`; tolerant of plaintext `ts|id|...`.
  - Response (persisted path):
    ~~~json
    {
      "ok": true,
      "_categories": ["AUTH","SETTINGS","RBAC","EVIDENCE","EXPORT","USER","SYSTEM"],
      "_retention_days": 365,
      "filters": { "order":"desc","limit":2,"cursor":null, "category":null, "action":null, "occurred_from":null, "occurred_to":null, "actor_id":null, "entity_type":null, "entity_id":null, "ip":null },
      "items": [
        {"id":"01J....","occurred_at":"2025-09-12T03:12:34Z","actor_id":1,"action":"rbac.user_role.attached","category":"RBAC","entity_type":"user","entity_id":"42","ip":"203.0.113.5","ua":"...","meta":{"role":"Auditor"}}
      ],
      "nextCursor": "ey4uLg"
    }
    ~~~
  - Response (stub path for empty DB and no business filters):
    ~~~json
    {
      "ok": true,
      "note": "stub-only",
      "_categories": ["AUTH","SETTINGS","RBAC","EVIDENCE","EXPORT","USER","SYSTEM"],
      "_retention_days": 365,
      "filters": {"order":"desc","limit":2,"cursor":null},
      "items": [ { "...": "three deterministic stub events ..." } ],
      "nextCursor": "..."
    }
    ~~~

**Audit — CSV Export**
- `GET /api/audit/export.csv` — CSV download of events matching the same filters as `GET /api/audit`.
  - Filters: `category`, `action`, `occurred_from`, `occurred_to`, `actor_id`, `entity_type`, `entity_id`, `ip`, `order`.
  - Sorting: `order` ∈ `asc|desc` (default `desc`).
  - Response: file download with headers:
    - `Content-Type: text/csv`  _(exactly; no charset)_
    - `Content-Disposition: attachment; filename="audit-<timestamp>.csv"`
    - `X-Content-Type-Options: nosniff`
  - Columns:
    - `id, occurred_at, actor_id, action, category, entity_type, entity_id, ip, ua, meta_json`
  - CSV format: RFC 4180 quoting via `fputcsv`.

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
      { "ok": false, "code": "EXPORT_TYPE_UNSUPPORTED" }
      ~~~
- Status:
  - `GET /api/exports/{jobId}/status`
  - Response:
    ~~~json
    { "ok": true, "status": "pending|running|completed|failed", "progress": 0, "jobId": "<ULID>", "id": "<ULID>" }
    ~~~
    - `id` is an alias of `jobId`.
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
      - CSV: `Content-Type: text/csv`, filename `export-<id>.csv`
      - JSON: `Content-Type: application/json`, filename `export-<id>.json`
      - PDF: `Content-Type: application/pdf`, filename `export-<id>.pdf`
- Artifact metadata (model fields):
  - `artifact_disk`, `artifact_path`, `artifact_mime`, `artifact_size`, `artifact_sha256`
  - `status`: `pending|running|completed|failed`; `progress` `0..100`
  - `completed_at`, `failed_at`, `error_code`, `error_note`

### Avatars
- `POST /api/avatar` — upload avatar; validates image; queues background transcode; returns 202 stub payload.
- `GET|HEAD /api/avatar/{user}?size=32|64|128` — serve WEBP variant from public disk.
  - Storage path: `public/avatars/{user}/avatar-<size>.webp`
  - Response headers:
    - `Content-Type: image/webp`
    - `X-Content-Type-Options: nosniff`
    - `Cache-Control: public, max-age=3600, immutable`

### Setup Wizard (Bugfix scope, Phase-4)
- **Status:** `GET /api/setup/status`
  - Response:
    ~~~json
    { "ok": true, "installed": false, "db_config_present": false, "steps_completed": [] }
    ~~~
  - When installed, returns `"installed": true`.
- **DB Test:** `POST /api/setup/db/test`
  - Request:
    ~~~json
    { "host":"...", "port":3306, "database":"...", "username":"...", "password":"..." }
    ~~~
  - Response 200 `{ "ok": true }` or 422 `{ "ok": false, "code":"DB_CONFIG_INVALID", "errors":{...} }`.
- **DB Write:** `POST /api/setup/db/write`
  - Writes config atomically to `core.setup.shared_config_path` and returns `{ "ok": true, "path": "/opt/phpgrc/shared/config.php" }`. If disabled: 400 `{ "ok": false, "code":"SETUP_STEP_DISABLED" }`.
- **App Key:** `POST /api/setup/app-key`
  - Generates Laravel app key. If `core.setup.allow_commands=false`, returns 202 `{ "ok": true, "note":"stub-only" }`. If already present: 409 `{ "ok": false, "code":"APP_KEY_EXISTS" }`.
- **Schema Init:** `POST /api/setup/schema/init`
  - Runs migrations (`--force`) when allowed. Else 202 stub.
- **Admin:** `POST /api/setup/admin`
  - Request:
    ~~~json
    { "name":"...", "email":"...", "password":"...", "password_confirmation":"..." }
    ~~~
  - Creates first admin or returns 409 `{ "code":"ADMIN_EXISTS" }`.
- **Admin TOTP Verify:** `POST /api/setup/admin/totp/verify`
  - Request `{ "code":"123456" }`. Response `{ "ok": true }` or 400 `{ "code":"TOTP_CODE_INVALID" }`.
- **SMTP:** `POST /api/setup/smtp` → stores SMTP settings; validates host/port/auth; 200 on success; 422 on invalid.
- **IdP:** `POST /api/setup/idp` → stores external IdP config; may return `{ "code":"IDP_UNSUPPORTED" }` if feature off.
- **Branding:** `POST /api/setup/branding` → stores name/theme/logo; 422 `{ "code":"BRANDING_INVALID" }` on invalid payload.
- **Finish:** `POST /api/setup/finish` → flips installed flag; subsequent setup routes return `{ "code":"SETUP_ALREADY_COMPLETED" }`.

---

## Retention

- Command: `php artisan audit:purge [--days=N] [--dry-run]`
  - On invalid `N` (<1 or >730) the command exits non-zero and prints `AUDIT_RETENTION_INVALID`.
  - Deletes rows with `occurred_at < now_utc - N days`.
- Scheduler:
  - Runs daily at **03:10 UTC**.
  - Scheduler clamps configured days to **[30, 730]** to reduce accidental loss.
  - Command enforces **[1, 730]** for manual runs.

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

// RBAC Roles and User-Role mapping
Route::prefix('/rbac')->middleware($rbacStack)->group(function () {
    Route::match(['GET','HEAD'], '/roles', [RolesController::class, 'index'])->defaults('roles', ['Admin']);
    Route::post('/roles', [RolesController::class, 'store'])->defaults('roles', ['Admin']);

    Route::match(['GET','HEAD'], '/users/{user}/roles', [UserRolesController::class, 'show'])->whereNumber('user')->defaults('roles', ['Admin']);
    Route::put('/users/{user}/roles', [UserRolesController::class, 'replace'])->whereNumber('user')->defaults('roles', ['Admin']);
    Route::post('/users/{user}/roles/{role}', [UserRolesController::class, 'attach'])->whereNumber('user')->defaults('roles', ['Admin']);
    Route::delete('/users/{user}/roles/{role}', [UserRolesController::class, 'detach'])->whereNumber('user')->defaults('roles', ['Admin']);
});

// Exports with RBAC + capability
Route::prefix('/exports')->middleware($rbacStack)->group(function () {
    Route::post('/{type}', [ExportController::class, 'createType'])->defaults('roles', ['Admin'])->defaults('capability', 'core.exports.generate');
    Route::post('/',       [ExportController::class, 'create'])->defaults('roles', ['Admin'])->defaults('capability', 'core.exports.generate');
    Route::get('/{jobId}/status',   [StatusController::class, 'show'])->defaults('roles', ['Admin','Auditor']);
    Route::get('/{jobId}/download', [ExportController::class, 'download'])->defaults('roles', ['Admin','Auditor']);
});

// Audit
Route::match(['GET','HEAD'], '/audit', [AuditController::class, 'index'])->middleware($rbacStack)->defaults('roles', ['Admin','Auditor']);
Route::get('/audit/export.csv', [AuditExportController::class, 'exportCsv'])->middleware($rbacStack)->defaults('roles', ['Admin','Auditor']);

// Evidence
Route::prefix('/evidence')->middleware($rbacStack)->group(function () {
    Route::match(['GET','HEAD'], '/', [EvidenceController::class, 'index'])->defaults('roles', ['Admin','Auditor']);
    Route::post('/', [EvidenceController::class, 'store'])->defaults('roles', ['Admin']);
    Route::match(['GET','HEAD'], '/{id}', [EvidenceController::class, 'show'])->defaults('roles', ['Admin','Auditor']);
});

// Avatars
Route::post('/avatar', [AvatarController::class, 'store']);
Route::match(['GET','HEAD'], '/avatar/{user}', [AvatarController::class, 'show'])->whereNumber('user');

// Setup Wizard (bugfix scope)
Route::prefix('/setup')->group(function () {
    Route::get('/status', [\App\Http\Controllers\Setup\SetupStatusController::class, 'show']);
    Route::post('/db/test', [\App\Http\Controllers\Setup\DbController::class, 'test']);
    Route::post('/db/write', [\App\Http\Controllers\Setup\DbController::class, 'write']);
    Route::post('/app-key', [\App\Http\Controllers\Setup\AppKeyController::class, 'generate']);
    Route::post('/schema/init', [\App\Http\Controllers\Setup\SchemaController::class, 'init']);
    Route::post('/admin', [\App\Http\Controllers\Setup\AdminController::class, 'create']);
    Route::post('/admin/totp/verify', [\App\Http\Controllers\Setup\AdminMfaController::class, 'verify']);
    Route::post('/smtp', [\App\Http\Controllers\Setup\SmtpController::class, 'store']);
    Route::post('/idp', [\App\Http\Controllers\Setup\IdpController::class, 'store']);
    Route::post('/branding', [\App\Http\Controllers\Setup\BrandingController::class, 'store']);
    Route::post('/finish', [\App\Http\Controllers\Setup\FinishController::class, 'finish']);
});
~~~

---

## Persistence & Queueing Notes
- When `core.exports.enabled=false` or `exports` table is absent, controllers return stub responses and never write files.
- Tests set `queue.default=sync` to run `GenerateExport` immediately.
- CSV uses RFC4180 quoting; JSON is UTF-8; PDF is a minimal valid single-page document.

---

## Web UI Notes (Phase 4)
- Hash-router under `/web` with admin pages:
  - `/admin/settings` — settings stub.
  - `/admin/roles` — role list and create role. Uses stub path when RBAC persistence disabled.
  - `/admin/user-roles` — assign roles to users (read, attach, detach, replace).

