# @phpgrc:/docs/phase-2/KICKOFF.md
# Phase 2 — Auth/Routing Kickoff

## Instruction Preamble
- **Date:** 2025-09-04  
- **Phase:** 2  
- **Goal:** Establish non-functional scaffolds for Auth/Routing to enable later feature work.  
- **Constraints:**  
  - Docs-first. No business logic beyond stubs.  
  - Full-file outputs only with header discriminator.  
  - Traceability to Charter and Roadmap.  
  - Deterministic mode.


## Scope
Implement scaffolding only:
- Laravel 11 API skeleton (no modules yet).  
- Sanctum SPA mode wiring (disabled until SPA binds).  
- TOTP/MFA placeholders (off by default).  
- Break-glass login placeholder (DB-flag, off by default).  
- Admin Settings UI framework skeleton (DB-backed to come later).  


## Out-of-Scope
- Real auth flows, real RBAC, real settings persistence.  
- External IdPs.  
- Audit/evidence pipelines.  
- UI beyond minimal routes and placeholders.


## Deliverables (docs + stubs)
**Docs**
- `/docs/phase-2/KICKOFF.md` (this file)
- `/docs/auth/PHASE-2-SPEC.md` (detailed contracts, to be produced next)
- API and Web stubs listed below.

**API (stubs only)**
- `/api/composer.json` (Laravel 11 skeleton declaration)
- `/api/app/Http/Controllers/Auth/LoginController.php` (placeholder)
- `/api/app/Http/Controllers/Auth/LogoutController.php` (placeholder)
- `/api/app/Http/Controllers/Auth/MeController.php` (placeholder)
- `/api/app/Http/Controllers/Auth/TotpController.php` (enroll/verify placeholders)
- `/api/app/Http/Controllers/Auth/BreakGlassController.php` (placeholder, gated by DB flag)
- `/api/app/Http/Middleware/AuthRequired.php` (placeholder)
- `/api/app/Http/Middleware/BreakGlassGuard.php` (placeholder)
- `/api/routes/api.php` (auth routes + health check placeholders)
- `/api/config/sanctum.php` (baseline + commented SPA mode)
- `/api/config/auth.php` (guards/providers skeleton)
- `/api/app/Models/User.php` (minimal fields; no business logic)
- `/api/database/migrations/...create_users_table.php` (placeholder filename only)
- `/api/database/migrations/...create_personal_access_tokens_table.php` (Sanctum placeholder)


**Web (stubs only)**
- `/web/src/lib/api/auth.ts` (client methods: login, logout, me, totp)
- `/web/src/routes/auth/Login.tsx` (placeholder)
- `/web/src/routes/auth/Mfa.tsx` (placeholder)
- `/web/src/routes/auth/BreakGlass.tsx` (placeholder)
- `/web/src/routes/auth/index.ts` (export registry)


**Settings placeholders (keys only, no UI yet)**
- `core.auth.local.enabled` (bool, default true)
- `core.auth.mfa.totp.required_for_admin` (bool, default true)
- `core.auth.break_glass.enabled` (bool, default false)


## API Endpoints (placeholders)
- `POST /api/auth/login` → 200 `{ok:true}` or 401; sets session cookie (future Sanctum).  
- `POST /api/auth/logout` → 204.  
- `GET  /api/auth/me` → 200 `{ user:{id,email,roles:[]} }` or 401.  
- `POST /api/auth/totp/enroll` → 200 `{ otpauthUri, secret }` (placeholder).  
- `POST /api/auth/totp/verify` → 200 `{ ok:true }` or 400 `{ code:"TOTP_CODE_INVALID" }`.  
- `POST /api/auth/break-glass` → 202 when DB flag enabled; always audited in future phases.  
- `GET  /api/health` → 200 `{ ok:true }`.


## Error Taxonomy (incremental)
- AUTH_DISABLED, AUTH_NOT_CONFIGURED  
- MFA_REQUIRED, TOTP_CODE_INVALID, MFA_NOT_ENROLLED  
- BREAK_GLASS_DISABLED, BREAK_GLASS_RATE_LIMITED  
- UNAUTHENTICATED, UNAUTHORIZED  
- INTERNAL_ERROR


## Sequencing
1) Create API skeleton and routes.  
2) Wire Sanctum config disabled-by-default.  
3) Add controllers and middleware placeholders.  
4) Add web route stubs and client API wrappers.  
5) Document settings keys and migration placeholders.  


## Migrations Clarification
- **Phase 1 (CORE-001):** `/api/database/migrations/...create_users_and_auth_tables.php` stubbed for installer/schema-init.  
- **Phase 2 (Auth/Routing):** `/api/database/migrations/0000_00_00_000000_create_users_table.php` and `/api/database/migrations/0000_00_00_000001_create_personal_access_tokens_table.php` provide Laravel-standard skeleton.  
- **Policy:** Keep both sets. Phase 1 migration remains reserved for installer flow; Phase 2 migrations satisfy Laravel skeleton and Sanctum requirements.


## Acceptance Criteria (Phase 2 kickoff)
- Files above exist with headers and TODOs.  
- Routes compile in skeleton form.  
- Sanctum config present, disabled by default.  
- No functional auth yet.  
- CI passes on stubs.


## Risks and Mitigations
- Scope creep into full auth → hold to placeholders and keys.  
- Config drift → centralize keys under `core.*` and enforce via migrations later.  


## References
- Charter Phase 2 scope.
- Roadmap Phase 2 items.
