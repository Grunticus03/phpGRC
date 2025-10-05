# Avatars API

Upload a WEBP avatar image and fetch sized variants.

- Base paths: `/api/avatar` and `/api/avatar/{user}`
- Config keys (Phase 4):  
  - `core.avatars.enabled` (default `true`)  
  - `core.avatars.size_px` (default `128`)  
  - `core.avatars.format` = `webp`  
  - `core.avatars.max_kb` soft cap for upload validation

## Upload avatar

`POST /api/avatar` — multipart/form-data

Fields:
- `file` — required, `.webp` file.
- `user_id` — optional target user id; defaults to authenticated user or `0`.

Validation errors (`422`):
```json
{
  "ok": false,
  "code": "AVATAR_VALIDATION_FAILED",
  "note": "stub-only",
  "errors": { "file": ["Only .webp is accepted in Phase 4."] }
}
```

Success (`202 Accepted`):
```json
{
  "ok": false,
  "note": "stub-only",
  "queued": true,
  "file": {
    "original_name": "me.webp",
    "mime": "image/webp",
    "size_bytes": 12345,
    "width": 128,
    "height": 128,
    "format": "webp"
  },
  "target": { "user_id": 42, "sizes": [32, 64, 128], "format": "webp" }
}
```
Notes:
- Returns `202` and queues a transcode job to generate `32/64/<size_px>` WEBP variants.
- If `core.avatars.enabled=false`, returns `400 { "ok": false, "code": "AVATAR_NOT_ENABLED" }`.

## Get avatar

`GET /api/avatar/{user}?size=32|64|128`

Responses:
- `200 OK` with `image/webp` body.
- `404` `{ "ok": false, "code": "AVATAR_NOT_FOUND", "user": 42, "size": 128 }`.
- `400` `{ "ok": false, "code": "AVATAR_NOT_ENABLED" }` if disabled.

Headers:
- `Cache-Control: public, max-age=3600, immutable`
- `X-Content-Type-Options: nosniff`
