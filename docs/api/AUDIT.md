# Audit API

Phase 4 behavior: read-only listing. Uses DB if table exists, else returns stub list.

## AuthZ
- Requires Gate `core.audit.view` (stub allows all in Phase 4).

## Event catalog (Phase 4)
- `setting.modified` — emitted once per setting changed when Admin Settings API persists updates. Includes sanitized diff in `meta`.
- `evidence.uploaded` — emitted on successful evidence POST.
- `evidence.downloaded` — emitted on GET evidence by id (200 only).
- `evidence.deleted` — emitted when evidence rows are deleted (future phases).

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
      "action": "setting.modified",
      "category": "SETTINGS",
      "entity_type": "core.setting",
      "entity_id": "core.rbac.require_auth",
      "ip": "203.0.113.10",
      "ua": "Mozilla/5.0",
      "meta": {
        "setting_key": "core.rbac.require_auth",
        "setting_label": "rbac.require_auth",
        "old_value": "false",
        "new_value": "true",
        "changes": [
          {
            "key": "core.rbac.require_auth",
            "old": false,
            "new": true,
            "action": "update"
          }
        ]
      }
    }
  ],
  "nextCursor": null,
  "_categories": ["AUTH","SETTINGS","RBAC","EVIDENCE","EXPORT","USER","SYSTEM"],
  "_retention_days": 365,
  "_cursor_echo": null
}
```
