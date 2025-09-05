## Instruction Preamble
- **Date:** 2025-09-04
- **Phase:** 2
- **Task:** Laravel API skeleton (no modules yet)
- **Goal:** Create a non-functional Laravel 11 API scaffold with placeholder routes, controllers, and configs aligned to kickoff spec. No business logic.
- **Constraints:**  
  - Docs-first, deterministic outputs.  
  - Full-file headers with discriminator.  
  - No DB I/O, no auth logic, no modules.  
  - CI must stay green.

## Scope
- Initialize `/api` as Laravel 11 skeleton.
- Add placeholder endpoints and middleware per kickoff: `auth login/logout/me`, `totp enroll/verify`, `break-glass`, `health`.
- Add `config/auth.php` and `config/sanctum.php` with commented defaults suitable for SPA mode later.
- Provide stub controllers, middleware, model, routes. No functionality.

## Out of Scope
- Sanctum wiring, sessions, tokens.
- RBAC, settings persistence.
- Migrations execution or schema changes.
- Any module code.

## Deliverables
- Laravel app skeleton under `/api` with Composer metadata.
- Route file with placeholder routes compiling.
- Empty controllers and middleware with TODOs.
- Minimal `User` model stub.
- Config stubs for `auth` and `sanctum` with comments indicating disabled state.
- Health check route returning a static placeholder.

## Acceptance Criteria
1. `/api` is a valid Laravel 11 app that boots locally in skeleton form.
2. `routes/api.php` defines placeholder endpoints:  
   - `POST /api/auth/login`  
   - `POST /api/auth/logout`  
   - `GET  /api/auth/me`  
   - `POST /api/auth/totp/enroll`  
   - `POST /api/auth/totp/verify`  
   - `POST /api/auth/break-glass`  
   - `GET  /api/health`
3. Controllers exist with method stubs that return fixed placeholder responses or `TODO` comments. No auth or DB calls.
4. Middleware stubs compile and are referenced where appropriate, but do nothing yet.
5. `config/auth.php` and `config/sanctum.php` exist with commented SPA settings. Sanctum stays disabled.
6. CI remains green with existing guardrails. No failing tests or linters introduced.
7. No new migrations are executed by default. Any placeholder migration files are named and empty.

## Definition of Done
- Branch builds pass CI.
- Files adhere to header discriminator rule.
- Session footer recorded in `docs/SESSION-LOG.md` at closeout.
- No runtime side effects beyond returning placeholder JSON.

## File Checklist (stubs only)
- `/api/composer.json`
- `/api/routes/api.php`
- `/api/app/Http/Controllers/Auth/LoginController.php`
- `/api/app/Http/Controllers/Auth/LogoutController.php`
- `/api/app/Http/Controllers/Auth/MeController.php`
- `/api/app/Http/Controllers/Auth/TotpController.php`
- `/api/app/Http/Controllers/Auth/BreakGlassController.php`
- `/api/app/Http/Middleware/AuthRequired.php`
- `/api/app/Http/Middleware/BreakGlassGuard.php`
- `/api/app/Models/User.php`
- `/api/config/auth.php`
- `/api/config/sanctum.php`
- *(optional, empty placeholders only)*  
  `/api/database/migrations/0000_00_00_000000_create_users_table.php`  
  `/api/database/migrations/0000_00_00_000001_create_personal_access_tokens_table.php`

## Implementation Notes
- Controllers return `response()->json(['ok'=>true])` or `response()->noContent()` as placeholders.
- Health route returns `{"ok":true}`.
- Keep Sanctum comments present but inactive to prevent auth coupling.
- Namespaces and filenames must match Laravel conventions to avoid autoload errors.

## Risks
- Scope creep into functional auth. Mitigation: enforce placeholders only.
- CI drift. Mitigation: run linters locally before PR.
