# Evidence API

Phase 4 behavior: persisted create/list/retrieve. Bytes stored in DB. Basic RBAC gate only (stub allows all).

## AuthZ
- Requires Gate `core.evidence.manage` (stub allows all in Phase 4).
- Capability `core.evidence.delete` gates the DELETE endpoint (defaults to enabled).

## Settings
- `core.evidence.enabled`: boolean, default true
- `core.evidence.max_mb`: integer MB size limit, default 25
- `core.evidence.allowed_mime`: allowlist, default `["application/pdf","image/png","image/jpeg","text/plain"]`
- `core.evidence.blob_storage_path`: string path for optional disk persistence, default `/opt/phpgrc/shared/blobs`

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

### DELETE /api/evidence/{id}

Responses
- 200 OK
```
{ "ok": true, "id": "ev_01J...", "deleted": true }
```
- 404 Not Found

**Audit**
- Emits `action="evidence.deleted"`, `category="EVIDENCE"`, `entity_type="evidence"`, `entity_id=<id>`.
- `meta`: `filename`, `mime`, `size_bytes`, `sha256`, `version`, `owner_id`.

## Versioning
- First upload per `(owner_id, filename)` starts at version 1. Subsequent uploads with the same tuple increment `version` in a transaction.

### POST /api/admin/evidence/purge
JSON

- `confirm`: required, boolean accepted value. Must be `true`.

Responses

- 200 OK

```
{ "ok": true, "deleted": 12 }
```

- 422 VALIDATION_FAILED when `confirm` is missing or false.
- 403 when caller lacks `core.evidence.manage`.

**Audit**
- Emits `action="evidence.purged"`, `category="EVIDENCE"`, `entity_type="evidence"`, `entity_id="all"` with `meta.deleted_count`.
