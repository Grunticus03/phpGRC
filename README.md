# phpGRC

Governance, Risk, and Compliance app. Laravel 11 JSON API + React/Vite SPA. OpenAPI 0.4.6. Phase-5 active.

## Stack
- PHP 8.3, Laravel 11, Sanctum
- MySQL 8 (SQLite for tests)
- Node 20, Vite + React + TypeScript
- CI: PHPUnit, PHPStan L9, Psalm, Pint, OpenAPI lint + breaking-change diff

## Quick start

```sh
# API
cp api/.env.example api/.env
(cd api && composer install)
# set DB_* in api/.env, then:
(cd api && php artisan key:generate && php artisan migrate)

# Web
(cd web && npm ci && npm run build)  # or: npm run dev
```

## Run

```sh
# API dev
(cd api && php artisan serve --host=0.0.0.0 --port=8000)

# Web dev with hash router
(cd web && npm run dev)   # SPA served at /, API expected at /api
```

## Tests

```sh
# API
(cd api && php artisan migrate:fresh --env=testing && composer test)

# Web
(cd web && npm test)
```

## CI gates
- Lint: Pint
- Static analysis: PHPStan level 9, Psalm clean
- Tests: PHPUnit green (SQLite + MySQL smoke)
- OpenAPI: lint + breaking-change diff
- Artifacts: junit.xml, coverage, build

## API surface

OpenAPI served:
- `GET /api/openapi.yaml`
- `GET /api/openapi.json`
- `GET /api/docs` (Swagger UI)

### Internal metrics endpoint (Phase-5)
Not in OpenAPI. Admin-only.

```
GET /api/dashboard/kpis
RBAC: roles ["Admin"], policy "core.metrics.view"
```

Response envelope:

```json
{
  "ok": true,
  "data": {
    "rbac_denies": {
      "window_days": 7,
      "from": "YYYY-MM-DD",
      "to": "YYYY-MM-DD",
      "denies": 0,
      "total": 0,
      "rate": 0.0,
      "daily": [{"date":"YYYY-MM-DD","denies":0,"total":0,"rate":0.0}]
    },
    "evidence_freshness": {
      "days": 30,
      "total": 0,
      "stale": 0,
      "percent": 0.0,
      "by_mime": [{"mime":"application/pdf","total":0,"stale":0,"percent":0.0}]
    }
  },
  "meta": { "generated_at": "ISO-8601" }
}
```

## RBAC defaults

`config/core.php`:

- Roles: `Admin`, `Auditor`, `Risk Manager`, `User`
- Policies:
  - `core.settings.manage` → Admin
  - `core.audit.view` → Admin, Auditor
  - `core.audit.export` → Admin
  - `core.metrics.view` → Admin
  - `core.users.view|manage` → Admin
  - `core.evidence.view` → Admin, Auditor
  - `core.evidence.manage` → Admin
  - `core.exports.generate` → Admin
  - `rbac.roles.manage` → Admin
  - `rbac.user_roles.manage` → Admin

Routes declare `roles` and `policy` defaults. `RbacMiddleware` enforces when `core.rbac.enabled=true`. Auth is required when `core.rbac.require_auth=true` (default except in tests).

## Brute-force guard (Phase-5)

`App\Http\Middleware\Auth\BruteForceGuard` protects `POST /api/auth/login`.

Config (`config/core.php → ['auth']['bruteforce']`):
- `CORE_AUTH_BF_ENABLED` (default true)
- `CORE_AUTH_BF_STRATEGY` `session|ip` (default `session`)
- `CORE_AUTH_BF_WINDOW_SECONDS` (default `900`)
- `CORE_AUTH_BF_MAX_ATTEMPTS` (default `5`)
- `CORE_AUTH_BF_LOCK_HTTP_STATUS` (default `429`)
- Session cookie name: `CORE_AUTH_SESSION_COOKIE_NAME` (default `phpgrc_auth_attempt`)

Lock behavior:
- Returns `429` with `Retry-After`
- Audits once per decision path

## Audit indexes

Composite indexes added for metrics:

- `(category, occurred_at)` → `idx_audit_cat_occurred_at`
- `(action, occurred_at)` → `idx_audit_action_occurred_at`

Keep a single idempotent migration for these. Remove duplicates to avoid `1061 Duplicate key name`.

## SPA

Routes (hash router):
- `/dashboard` → KPI tiles (Admin-only; shows 403 message if forbidden)
- `/admin` → index
- `/admin/settings`, `/admin/roles`, `/admin/user-roles`, `/admin/audit`

Client API:
- `web/src/lib/api/metrics.ts` fetches and unwraps `{ ok, data }`.

Nav:
- `Dashboard` link in header
- Skip-link targets `<main id="main">`

## Troubleshooting

- If PHP fails autoloading on routes, ensure `use` imports in `api/routes/api.php` use backslashes:
  `use Illuminate\Support\Facades\Route;` not `use Illuminate/Support/Facades/Route;`.

- If tests fail on duplicate index names, ensure only one audit-index migration remains, or keep the idempotent version.

## Security
- MFA TOTP defaults in `core.php`; Admins require TOTP by default.
- Break-glass route guarded and disabled by default.
