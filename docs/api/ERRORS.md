# Error Envelopes

## Validation Errors (Spec shape)

Returned by endpoints following the spec payload shape:

```json
{
  "ok": false,
  "code": "VALIDATION_FAILED",
  "errors": {
    "<section>": {
      "<field>": ["message 1", "message 2"]
    }
  },
  "message": "First validation error message"
}
```

- `errors` are grouped by **section** (e.g., `rbac`, `audit`, `evidence`, `avatars`), then by field.
- Array indices (e.g., `evidence.allowed_mime.0`) are collapsed into the field key.

## Validation Errors (Legacy shape)

When the request used the legacy `{ "core": { ... } }` payload:

```json
{
  "errors": {
    "<section>": {
      "<field>": ["message 1", "message 2"]
    }
  }
}
```

## Authorization

- **401 Unauthorized** — when `rbac.require_auth=true` and no authenticated user.  
- **403 Forbidden** — when user lacks required roles/capabilities/policies:

```json
{ "ok": false, "code": "FORBIDDEN", "message": "Forbidden" }
```
