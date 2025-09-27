# @phpgrc:/docs/phase-5/RBAC-USER-SEARCH.md
# FILE: /docs/phase-5/RBAC-USER-SEARCH.md

# Phase 5 — RBAC User Search (Internal Note)

## Instruction Preamble
- Date: 2025-09-26
- Scope: Internal admin-only endpoint. Not part of public OpenAPI. For operators and testers.

## Endpoint
- Method: GET  
- Path (external): `/api/rbac/users/search`  
- Internal name: `rbac/users/search`

## Auth / RBAC
- Default roles: `['Admin']`
- Required policy: `core.users.view`
- Honors global RBAC toggles and persist vs stub semantics.

## Query Parameters
- `q` (string, required): case-insensitive substring match on **name** or **email**.
- `limit` (int, optional): range **1..100**, default **20**. Values outside range are clamped.

## Response
`200 OK`:
```json
{
  "ok": true,
  "data": [
    {"id":"01HJ...","name":"Alice Admin","email":"alice@example.com"},
    {"id":"01HJ...","name":"Bob Auditor","email":"bob@example.com"}
  ]
}
```

### Errors
- `401` when unauthenticated and `core.rbac.require_auth=true`.
- `403` when role/policy fails.

## Examples

### Request
```bash
curl -sS -H "Authorization: Bearer <token>" \
  "https://<host>/api/rbac/users/search?q=ali&limit=10"
```

### Success
```json
{
  "ok": true,
  "data": [
    {"id":"01HX...","name":"Alice Admin","email":"alice@example.com"}
  ]
}
```

### Forbidden
```json
{
  "ok": false,
  "code": "FORBIDDEN",
  "message": "Not authorized."
}
```

## Notes
- Field set is minimal by design: `id`, `name`, `email`.
- Pagination is not implemented; use `limit` appropriately.
- This endpoint is **intentionally excluded** from `docs/api/openapi.yaml`.
- See tests: `api/tests/Unit/Rbac/UserSearchControllerTest.php`.
