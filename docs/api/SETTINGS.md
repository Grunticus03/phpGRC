# Admin Settings API

Phase 4 behavior: validate only. No persistence. Emits audit events if `audit_events` table exists and `core.audit.enabled=true`.

## AuthZ
- Requires Gate `core.settings.manage` (stub allows all in Phase 4).

## Endpoints

### GET /api/admin/settings
Returns current effective config defaults (from `config/core.php`).

Response 200
```
{
  "ok": true,
  "config": {
    "core": {
      "rbac": { "enabled": true, "roles": ["Admin","Auditor","Risk Manager","User"] },
      "audit": { "enabled": true, "retention_days": 365 },
      "evidence": { "enabled": true, "max_mb": 25, "allowed_mime": ["application/pdf","image/png","image/jpeg","text/plain"] },
      "avatars": { "enabled": true, "size_px": 128, "format": "webp" }
    }
  }
}
```

### POST /api/admin/settings  (alias: PUT /api/admin/settings)
Accepts either:
- Top-level sections: `{ "rbac": {...}, "audit": {...}, "evidence": {...}, "avatars": {...} }`
- Legacy envelope: `{ "core": { ...same as above... } }`

Validation rules
- `rbac`: object
  - `enabled`: boolean
  - `roles`: array<string> min 1, each 1..64 chars
- `audit`: object
  - `enabled`: boolean
  - `retention_days`: integer 1..730
- `evidence`: object
  - `enabled`: boolean
  - `max_mb`: integer 1..500
  - `allowed_mime`: array<string> subset of configured allowlist
- `avatars`: object
  - `enabled`: boolean
  - `size_px`: integer in {128}
  - `format`: string in {"webp"}

Responses
- 200 OK
```
{
  "ok": true,
  "applied": false,
  "note": "stub-only",
  "accepted": { "...": "validated input by section" },
  "audit_logged": 0
}
```
- 422 Unprocessable Entity
```
{ "ok": false, "code": "VALIDATION_FAILED", "errors": { "<path>": ["message"] } }
```

## Audit
- For each accepted section, emits `settings.update` with `category=SETTINGS`, `entity_type=core.config`, `entity_id=<section>`, and `meta.changes` = validated changes. Only if table exists and audit enabled.
