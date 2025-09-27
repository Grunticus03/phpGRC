/// FILE: docs/internal/RBAC-USER-SEARCH.md
# Admin User Search API

Search application users by **name** or **email**. Admin-only. Returns basic identity fields only.

- Route: `GET /api/rbac/users/search`
- Auth: Bearer token (Sanctum). Enforced by RBAC middleware.
- RBAC: requires policy `core.users.view` and role `Admin`
- Purpose: admin UX autocomplete and account lookup
- Fields returned: `id`, `name`, `email`
- Paging: cursorless; page/size with upper bound

## Query parameters

| Name       | Type    | Required | Notes                                         |
|------------|---------|----------|-----------------------------------------------|
| `q`        | string  | yes      | Case-insensitive substring on name or email.  |
| `page`     | integer | no       | Default **1**. Minimum 1.                     |
| `per_page` | integer | no       | Default **50**. Min 1. Max **500**.           |

## Semantics

- Matching is `%q%` against `name` or `email` (SQL `LIKE` with escaped wildcards).
- Results are ordered by `id ASC` for stable, deterministic pagination.
- When the `users` table is unavailable (e.g., setup), returns `200` with empty `data` and `meta.total=0`.
- Validation errors return `422` with `{ ok:false, code:"VALIDATION_FAILED" }`.

## Responses

### 200 OK

```json
{
  "ok": true,
  "data": [
    { "id": 1, "name": "Alpha 01", "email": "alpha01@example.test" }
  ],
  "meta": { "page": 1, "per_page": 50, "total": 80, "total_pages": 2 }
}
```

### 422 VALIDATION_FAILED

```json
{
  "ok": false,
  "code": "VALIDATION_FAILED",
  "message": "Query is required.",
  "errors": { "q": ["The q field is required."] }
}
```

### 401 / 403

Standard auth/RBAC errors per platform conventions.

## Examples

### Basic search

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
  --get 'https://<host>/api/rbac/users/search' \
  --data-urlencode 'q=alpha'
```

### Paged search

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
  --get 'https://<host>/api/rbac/users/search' \
  --data-urlencode 'q=alpha' \
  --data-urlencode 'page=2' \
  --data-urlencode 'per_page=100'
```

## Notes for clients

- Do not assume full-text search; use simple prefix/contains UX.
- Clamp `per_page` client-side to ≤500 to avoid 422 on future stricter servers.
- Treat empty `data` with `total=0` as “no matches” or “directory unavailable”, based on setup phase.
