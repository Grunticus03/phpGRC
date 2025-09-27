# @phpgrc:/docs/phase-5/RBAC-USER-SEARCH.md
# Phase 5 — RBAC User Search

## Preamble
- Date: 2025-09-26
- Scope: Admin-only endpoint. Now included in OpenAPI. Auth required.

## Endpoint
- Method: GET
- Path: `/api/rbac/users/search`
- Auth: Bearer (Sanctum). Always enforced.
- RBAC: role `Admin`, policy `core.users.view`.

## Query Parameters
- `q` (string, required): case-insensitive substring on **name** or **email** only.
- `page` (int, optional): default `1`, min `1`.
- `per_page` (int, optional): default `50`, max `500`.

## Response
`200 OK`:
```json
{
  "ok": true,
  "data": [
    {"id":"01HJ...","name":"Alice Admin","email":"alice@example.com"},
    {"id":"01HJ...","name":"Bob Auditor","email":"bob@example.com"}
  ],
  "meta": {
    "page": 1,
    "per_page": 50,
    "total": 123,
    "total_pages": 3
  }
}
```

Errors:
- `401` unauthenticated.
- `403` RBAC denied.

## Notes
- Fields returned: `id`, `name`, `email`.
- Production uses page-based pagination only.
- Tests: `api/tests/Unit/Rbac/UserSearchControllerTest.php` will assert auth and pagination.
