# Admin Settings API

Phase 4 behavior: **validate-only by default**. Persistence happens **only** when:
- the request includes `"apply": true`, **and**
- the `core_settings` table exists (migrations applied).

If either condition is false, the endpoint validates input and returns `note: "stub-only"` without writing to storage.

> **RBAC toggle scope**
>
> - The settings contract exposes only `rbac.enabled` and `rbac.roles`.
> - The runtime flag `rbac.require_auth` is a system/runtime setting (read from config) and **not** part of the Admin Settings API payload or responses. It is enforced by `RbacMiddleware`.

## Authorization

- When RBAC is enabled, access is guarded by `RbacMiddleware` and the route defaults (Admin-only).  
- If `rbac.require_auth=true`, authentication is required; otherwise anonymous read is allowed but write still validates only unless `apply=true` and persistence is available.

## Endpoints

### GET `/api/admin/settings`

Returns the effective core settings (defaults merged with persisted overrides; filtered to the contract keys).

**Response 200**
```json
{
  "ok": true,
  "config": {
    "core": {
      "rbac":   { "enabled": true, "roles": ["Admin","Auditor","Risk Manager","User"] },
      "audit":  { "enabled": true, "retention_days": 365 },
      "evidence": { "enabled": true, "max_mb": 25, "allowed_mime": ["application/pdf","image/png","image/jpeg","text/plain"] },
      "avatars":  { "enabled": true, "size_px": 128, "format": "webp" }
    }
  }
}
```

### POST `/api/admin/settings`  (alias: `PUT`/`PATCH`)

Accepts **either** payload shape:

1) **Spec (preferred)** — top-level sections:
```json
{
  "rbac":    { "enabled": true, "roles": ["Admin","Auditor","Risk Manager","User"] },
  "audit":   { "enabled": true, "retention_days": 365 },
  "evidence":{ "enabled": true, "max_mb": 25, "allowed_mime": ["application/pdf","image/png","image/jpeg","text/plain"] },
  "avatars": { "enabled": true, "size_px": 128, "format": "webp" },
  "apply":   false
}
```

2) **Legacy envelope** — `{ "core": { ... } }`:
```json
{
  "core": {
    "rbac":    { "enabled": true, "roles": ["Admin","Auditor","Risk Manager","User"] },
    "audit":   { "enabled": true, "retention_days": 365 },
    "evidence":{ "enabled": true, "max_mb": 25, "allowed_mime": ["application/pdf","image/png","image/jpeg","text/plain"] },
    "avatars": { "enabled": true, "size_px": 128, "format": "webp" }
  },
  "apply": false
}
```

**Validation rules**
- `rbac.enabled`: boolean  
- `rbac.roles`: array<string> min 1, each 1..64 chars
- `audit.enabled`: boolean  
- `audit.retention_days`: integer 1..730
- `evidence.enabled`: boolean  
- `evidence.max_mb`: integer ≥1 and ≤500  
- `evidence.allowed_mime`: array<string> each must be in the configured allowlist
- `avatars.enabled`: boolean  
- `avatars.size_px`: **128** (fixed in Phase 4)  
- `avatars.format`: `"webp"` (fixed in Phase 4)
- `apply`: boolean (default `false`)

**Responses**
- **200 OK** (stub or applied)
  - Stub-only (no persistence or `apply=false`):
    ```json
    { "ok": true, "applied": false, "note": "stub-only", "accepted": { "...": "validated sections" } }
    ```
  - Applied (persistence available and `apply=true`):
    ```json
    { "ok": true, "applied": true, "accepted": { "...": "validated sections" }, "changes": [ { "key": "core.audit.retention_days", "old": 365, "new": 180, "action": "update" } ] }
    ```
- **422 Unprocessable Entity**
  - Spec shape:
    ```json
    { "ok": false, "code": "VALIDATION_FAILED", "errors": { "audit": { "retention_days": ["The audit.retention_days must be between 1 and 730."] } }, "message": "The audit.retention_days must be between 1 and 730." }
    ```
  - Legacy shape:
    ```json
    { "errors": { "audit": { "retention_days": ["The audit.retention_days must be between 1 and 730."] } } }
    ```
- **403 Forbidden**
  - When RBAC denies role/capability/policy for the caller:
    ```json
    { "ok": false, "code": "FORBIDDEN", "message": "Forbidden" }
    ```

## Audit

When **applied** (i.e., not stub-only), a `SettingsUpdated` domain event is emitted with a `changes` array describing keys set/updated/unset. In stub-only responses, no persistence and no change event are produced.

## Notes

- The effective response and accepted payloads **exclude** non-contract keys. In particular, `rbac.require_auth` remains a runtime/system flag and is not returned by or accepted into the Admin Settings API.
