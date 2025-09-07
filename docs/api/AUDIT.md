# Audit API

Phase 4 behavior: read-only listing. Uses DB if table exists, else returns stub list.

## AuthZ
- Requires Gate `core.audit.view` (stub allows all in Phase 4).

## Event catalog (Phase 4)
- `settings.update` — emitted by Admin Settings API per section accepted.
- `evidence.upload` — emitted on successful evidence POST.
- `evidence.read` — emitted on GET evidence by id (200 only).
- `evidence.head` — emitted on HEAD evidence by id (200 only).

## Endpoint

### GET /api/audit
Query
- `limit`: integer 1..100, default 25
- `cursor`: base64url JSON `{"ts":"ISO8601","id":"<ulid>"}` for keyset pagination

Responses
- 200 OK
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
  "nextCursor": null,
  "_categories": ["AUTH","SETTINGS","RBAC","EVIDENCE","EXPORT","USER","SYSTEM"],
  "_retention_days": 365,
  "_cursor_echo": null
}
```
