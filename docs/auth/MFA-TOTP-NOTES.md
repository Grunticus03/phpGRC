# MFA (TOTP) — Scaffold Notes

**Phase:** 2  
**Status:** Scaffolded, disabled. No runtime checks.

## Defaults (config)
- `config('core.auth.mfa.totp.required_for_admin')` → `true` (no enforcement yet)
- `config('core.auth.mfa.totp.{issuer,digits,period,algorithm}')` → placeholders

## Environment (optional placeholders)
```
env
CORE_AUTH_LOCAL_ENABLED=true
CORE_AUTH_MFA_TOTP_REQUIRED_FOR_ADMIN=true
CORE_AUTH_MFA_TOTP_ISSUER=phpGRC
CORE_AUTH_BREAK_GLASS_ENABLED=false
```

## Enablement later
- Implement checks in `app/Http/Middleware/MfaRequired.php` and attach to protected routes.
- Persist settings in DB and expose in Admin Settings UI (CORE-003).
- Audit enroll/verify actions and failures (Phase 4+).

## Rollback
- Once enforcement exists, remove the middleware from affected routes and clear config cache.
