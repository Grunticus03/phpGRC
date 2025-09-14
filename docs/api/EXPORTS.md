# Exports API

Create export jobs and download completed artifacts.

- Base path: `/api/exports`
- Capability gate: `core.exports.generate=true` required to create jobs.
- RBAC: `roles: ["Admin"]` to create; `["Admin","Auditor"]` to view status or download.

## Create job (legacy body)

`POST /api/exports`

Request (JSON):
```json
{ "type": "csv", "params": { "scope": "all" } }
```

Responses:
- `202 Accepted` (stub or queued)
  ```json
  { "ok": true, "jobId": "exp_stub_0001", "type": "csv", "params": {"scope":"all"}, "note": "stub-only" }
  ```
- `422` when `type` not in `csv|json|pdf`:
  ```json
  { "ok": false, "code": "EXPORT_TYPE_UNSUPPORTED", "note": "stub-only" }
  ```
- `403` if capability or role denied.

## Create job by type

`POST /api/exports/{type}` where `{type}` ∈ `csv|json|pdf`

Request (JSON, optional):
```json
{ "params": { "scope": "all" } }
```

Responses:
- `202 Accepted` same shape as above.
- `422` for unsupported type.
- `403` if capability or role denied.

## Check job status

`GET /api/exports/{jobId}/status`

Response:
```json
{ "ok": true, "status": "queued" }
```
or `"running" | "done" | "failed"`

- `404` if job not found.

## Download artifact

`GET /api/exports/{jobId}/download`

- `200 OK` with `Content-Type` set to the artifact mime.
- `404` with `{ "ok": false, "code": "EXPORT_NOT_READY" }` until completed.
- `409` with `{ "ok": false, "code": "EXPORT_FAILED", "errorCode": "...", "errorNote": "..." }` if failed.
- `410` `{ "ok": false, "code": "EXPORT_ARTIFACT_MISSING" }` if file missing.

## Notes

- Persistence must be enabled: `core.exports.enabled=true` and the `exports` table present; otherwise responses include `"note":"stub-only"`.
- Artifacts are stored on `core.exports.disk` under `core.exports.dir`.
