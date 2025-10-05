# Break-glass Login — Scaffold Notes

**Phase:** 2  
**Status:** Disabled by default. Guard returns 404 when off.

## Default state
- `config('core.auth.break_glass.enabled')` → `false` (see `/api/config/core.php`)
- `BreakGlassGuard` blocks the route with 404 and `{"error":"BREAK_GLASS_DISABLED"}`

## To enable later
1) Set env:
```
env
CORE_AUTH_BREAK_GLASS_ENABLED=true
```

2) Clear caches:
```
bash
php artisan config:clear && php artisan cache:clear
```

3) Add rate limiting, MFA requirement, and audit hooks in later phases.

## Security intent
- 404 when disabled to reduce endpoint discovery.
- Full audit and justification will be enforced when enabled in Phase 2/5.
