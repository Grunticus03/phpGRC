# Auth/Routing — Phase 2 Spec

## Endpoints (placeholders)
- POST `/api/auth/login` → 200 `{ok:true}` | 401
- POST `/api/auth/logout` → 204
- GET  `/api/auth/me` → 200 `{user:{id,email,roles:[]}}` | 401
- POST `/api/auth/totp/enroll` → 200 `{otpauthUri,secret}` (placeholder)
- POST `/api/auth/totp/verify` → 200 `{ok:true}` | 400 `{code:"TOTP_CODE_INVALID"}`
- POST `/api/auth/break-glass` → 202 when DB flag enabled
- GET  `/api/health` → 200 `{ok:true}`

## Settings keys
- `core.auth.local.enabled` (bool, default true)
- `core.auth.mfa.totp.required_for_admin` (bool, default true)
- `core.auth.break_glass.enabled` (bool, default false)

## Error taxonomy
AUTH_DISABLED, AUTH_NOT_CONFIGURED, MFA_REQUIRED, TOTP_CODE_INVALID, MFA_NOT_ENROLLED,  
BREAK_GLASS_DISABLED, BREAK_GLASS_RATE_LIMITED, UNAUTHENTICATED, UNAUTHORIZED, INTERNAL_ERROR

## Migrations Policy
- **Phase 1 (CORE-001):** `/api/database/migrations/...create_users_and_auth_tables.php` — installer/schema-init stub.  
- **Phase 2 (Auth/Routing):**  
  - `/api/database/migrations/0000_00_00_000000_create_users_table.php`  
  - `/api/database/migrations/0000_00_00_000001_create_personal_access_tokens_table.php`  
- **Policy:** Both sets must coexist.  
  - Phase 1 migration is bound to the installer flow and setup wizard.  
  - Phase 2 migrations provide Laravel-standard user table and Sanctum tokens baseline.  
  - Future reconciliation of schemas happens when CORE-004 (RBAC roles) introduces full user model.

## Sequencing
1) API skeleton and routes
2) Sanctum config disabled-by-default
3) Controllers + middleware placeholders
4) SPA route stubs + client wrappers
5) Migrations placeholders (not executed yet, coexist with Phase 1 installer stub)

## Non-goals
- No external IdPs
- No RBAC implementation
- No persistence beyond placeholders
