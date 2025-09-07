# Evidence API

Phase 4 behavior: persisted create/list/retrieve. Bytes stored in DB. Basic RBAC gate only (stub allows all).

## AuthZ
- Requires Gate `core.evidence.manage` (stub allows all in Phase 4).

## Settings
- `core.evidence.enabled`: boolean, default true
- `core.evidence.max_mb`: integer MB size limit, default 25
- `core.evidence.allowed_mime`: allowlist, default `["application/pdf","image/png","image/jpeg","text/plain"]`

## Endpoints

### POST /api/evidence
Multipart
- `file`: required, file, max size `max_mb` MB, `mimetypes` in allowlist

Responses
- 201 Created
```
{
  "ok": true,
  "id": "ev_01J...",
  "version": 1,
  "sha256": "<hex>",
  "size": 12345,
  "mime": "application/pdf",
  "name": "filename.pdf"
}
```
- 400 Bad Request when disabled
```
{ "ok": false, "code": "EVIDENCE_NOT_ENABLED" }
```
- 422 Validation error per `StoreEvidenceRequest`

**Audit**
- Emits `action="evidence.upload"`, `category="EVIDENCE"`, `entity_type="evidence"`, `entity_id=<id>`.
- `meta`: `filename`, `mime`, `size_bytes`, `sha256`, `version`.

### GET /api/evidence
Query
- `limit`: 1..100, default 20
- `cursor`: opaque base64 string of `"Y-m-d H:i:s|<id>"`

Response 200
```
{
  "ok": true,
  "data": [
    {
      "id": "ev_01J...",
      "owner_id": 1,
      "filename": "file.pdf",
      "mime": "application/pdf",
      "size_bytes": 12345,
      "sha256": "<hex>",
      "version": 1,
      "created_at": "2025-09-05T12:00:00Z"
    }
  ],
  "next_cursor": null
}
```

### GET /api/evidence/{id}
Headers
- Supports `If-None-Match` with strong ETag `"<sha256>"`.

Responses
- 200 OK with binary body. Headers:
  - `Content-Type`, `Content-Length`, `ETag`, `Content-Disposition`, `X-Content-Type-Options: nosniff`
- 304 Not Modified when ETag matches
- 404 Not Found
- `HEAD` verb returns headers only with 200.

**Audit**
- On 200 responses:
  - GET → `action="evidence.read"`, `category="EVIDENCE"`
  - HEAD → `action="evidence.head"`, `category="EVIDENCE"`
- Not logged for 304 or 404.

## Versioning
- First upload per `(owner_id, filename)` starts at version 1. Subsequent uploads with the same tuple increment `version` in a transaction.
