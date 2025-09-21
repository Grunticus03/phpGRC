# @phpgrc:/docs/phase-5/RBAC-POLICYMAP-NOTES.md
# Phase 5 — RBAC PolicyMap Overrides & Middleware Check Grid

## Instruction Preamble
- Date: 2025-09-19
- Phase: 5 (prep)
- Goal: Specify `PolicyMap` override rules and the enforcement grid for `RbacMiddleware`.
- Constraints: Do not change OpenAPI 0.4.6. Keep Phase-4 stub vs persist semantics intact. Docs-only.

---

## 1) Scope
Define:
- Allowed shape and normalization for `core.rbac.policies` overrides.
- Evaluation order in `RbacMiddleware` for auth, capability, roles, policy.
- Stub vs persist behavior.
- Deterministic outcomes for common routes and caller states (check grid).
- Audit logging behavior and UI display conventions.
- Auth brute-force controls and audit addendum.

---

## 2) Terminology
- **Role**: Human display name. Persisted catalog optional. IDs are `role_<slug>`; policy uses display names.
- **Policy**: Named permission key evaluated by `RbacEvaluator` via PolicyMap.
- **Capability**: Boolean feature gate in config (e.g., `core.exports.generate`).
- **Mode**:
  - **stub**: policies allow; unknown policy keys allow.
  - **persist**: policies enforce; unknown policy keys deny.

---

## 3) Default PolicyMap (effective base)
```json
{
  "core.settings.manage":  ["Admin"],
  "core.audit.view":       ["Admin", "Auditor"],
  "core.evidence.view":    ["Admin", "Auditor"],
  "core.evidence.manage":  ["Admin"],
  "core.exports.generate": ["Admin"],
  "rbac.roles.manage":     ["Admin"],
  "rbac.user_roles.manage":["Admin"]
}
```
Overrides come from `config('core.rbac.policies')`.

---

## 4) Override rules (`core.rbac.policies`)
Shape:
```php
[
  'policy.key' => ['Role Name 1', 'Role Name 2', /* ... */],
  // ...
]
```

Rules:
1. **Replace, not merge**: If a key is present, its array replaces the default list.
2. **Empty list ⇒ deny**: In **persist**, `[]` denies to everyone. In **stub**, allow.
3. **Normalization**:
   - Trim. Collapse internal whitespace to a single space.
   - Case-insensitive match to catalog names. Resolve to canonical display names.
   - Deduplicate after normalization.
4. **Unknown roles**:
   - **persist**: drop unknown names. If the list becomes empty, rule 2 applies. Log `WARNING` and audit `RBAC`/`rbac.policy.override.unknown_role` with `policy`, `unknown_roles`.
   - **stub**: accept for logging only; allow outcome unchanged.
5. **Unknown policy keys**:
   - **stub**: allow.
   - **persist**: deny; audit `RBAC`/`rbac.policy.unknown_key`.
6. **Source precedence** (effective config at request time):
   1) base app config
   2) overlay file `/opt/phpgrc/shared/config.php`
   3) test/runtime injection (e.g., during Feature tests)
   > `.env` does not participate at runtime; production must use cached config.

---

## 5) Role catalog source
- **persist path**: DB roles if persistence enabled and tables exist.
- **stub path**: `core.rbac.roles` config.
- Catalog is the authority for normalization and “unknown role” detection.

---

## 6) Middleware evaluation order
Per request. Inputs may be attached to route: `roles: string[]`, `policy: string`, `capability: string`.

1. **RBAC enabled?** `core.rbac.enabled`
   - If `false`:
     - **RBAC endpoints** (`/api/rbac/*`): `404` with `{code:"RBAC_DISABLED"}`.
     - Others: skip role/policy. Capability gate may still apply.
2. **Auth gate** (`core.rbac.require_auth`)
   - If `true` and no user: `401 UNAUTHENTICATED`.
3. **Capability gate** (if route declares one)
   - If `config("core.capabilities.$capability") !== true`: `403 FORBIDDEN`.
4. **Role gate** (if route declares `roles[]` and RBAC enabled)
   - Any-of match. If user lacks all: `403 FORBIDDEN`.
5. **Policy gate** (if route declares `policy` and RBAC enabled)
   - **stub**: allow.
   - **persist**:
     - If key missing in effective PolicyMap: `403 FORBIDDEN`.
     - Else allow if user has any role in list; else `403 FORBIDDEN`.

Notes:
- Capability can deny even if role/policy pass.
- Routes may specify any subset of `{roles, policy, capability}`.

---

## 7) Deterministic check grid

Legend:
- RA = `core.rbac.enabled`
- AUTH = `core.rbac.require_auth`
- MODE = `core.rbac.mode` (`stub`|`persist`)
- Caller: A0=anonymous, U0=authed no roles, UA=authed Admin, UU=authed Auditor

| Route (example)                              | RA  | AUTH | MODE    | roles[]              | policy                    | capability               | Caller | Result |
|---------------------------------------------|-----|------|---------|----------------------|---------------------------|--------------------------|--------|--------|
| GET `/api/audit`                            | T   | T    | persist | —                    | core.audit.view           | —                        | A0     | 401    |
| GET `/api/audit`                            | T   | T    | persist | —                    | core.audit.view           | —                        | U0     | 403    |
| GET `/api/audit`                            | T   | T    | persist | —                    | core.audit.view           | —                        | UU     | 200    |
| GET `/api/audit`                            | T   | F    | stub    | —                    | core.audit.view           | —                        | A0     | 200    |
| POST `/api/settings`                        | T   | T    | persist | —                    | core.settings.manage      | —                        | UA     | 200    |
| POST `/api/settings`                        | T   | T    | persist | —                    | core.settings.manage      | —                        | UU     | 403    |
| POST `/api/evidence`                        | T   | T    | persist | —                    | core.evidence.manage      | —                        | UA     | 200    |
| POST `/api/evidence`                        | T   | T    | persist | —                    | core.evidence.manage      | —                        | UU     | 403    |
| GET `/api/evidence`                         | T   | T    | persist | —                    | core.evidence.view        | —                        | UU     | 200    |
| POST `/api/exports`                         | T   | T    | persist | —                    | core.exports.generate     | core.exports.generate    | UA     | 200    |
| POST `/api/exports` (capability off)        | T   | T    | persist | —                    | core.exports.generate     | core.exports.generate=F  | UA     | 403    |
| GET `/api/rbac/roles`                       | T   | T    | persist | —                    | rbac.roles.manage         | —                        | UA     | 200    |
| GET `/api/rbac/roles`                       | T   | T    | persist | —                    | rbac.roles.manage         | —                        | UU     | 403    |
| POST `/api/rbac/users/{id}/roles:attach`    | T   | T    | persist | —                    | rbac.user_roles.manage    | —                        | UA     | 200    |
| POST `/api/rbac/users/{id}/roles:attach`    | T   | T    | persist | —                    | rbac.user_roles.manage    | —                        | U0     | 403    |
| Any route with `roles:["Admin"]` only       | T   | T    | persist | ["Admin"]            | —                         | —                        | UA     | 200    |
| Any route with `roles:["Admin"]` only       | T   | T    | persist | ["Admin"]            | —                         | —                        | UU     | 403    |
| Any route with unknown policy in **persist** | T   | T    | persist | —                    | unknown.key               | —                        | UA     | 403    |
| Any route with unknown policy in **stub**    | T   | T    | stub    | —                    | unknown.key               | —                        | U0     | 200    |
| Any route when RA = false (non-RBAC path)   | F   | —    | —       | —                    | —                         | —                        | any    | 200(*) |
| Any `/api/rbac/*` when RA = false           | F   | —    | —       | —                    | —                         | —                        | any    | 404    |

(*) Subject to capability gates if declared.  
Front-end: when Result=403, render a custom “Permission denied” page. Never use 404 for denies when RBAC is enabled.

---

## 8) Logging, audit, and UI display
- **Storage**: persist canonical `category` and `action` codes (e.g., `RBAC` / `rbac.deny.unauthenticated`).
- **UI**: show a human-readable label derived from a deterministic map, with the canonical code as a chip or tooltip.
  - Examples:  
    - `rbac.deny.unauthenticated` → “Denied: Anonymous”  
    - `rbac.deny.role` → “Denied: Missing role”  
    - `rbac.deny.policy` → “Denied: Policy not satisfied”
- **On deny**: log `INFO` with `reason` (`unauthenticated|capability|role|policy|unknown_policy`), `route`, `user_id|"anonymous"`.
- **Audit on**:
  - Unknown policy key: `RBAC` / `rbac.policy.unknown_key` (meta: `policy`, `route`).
  - Override with unknown role(s): `RBAC` / `rbac.policy.override.unknown_role` (meta: `policy`, `unknown_roles`).
- Exactly one audit record per request outcome. No duplicates.

---

## 9) Test checklist (Phase-5 PR gate)
- **Evaluator unit tests**
  - Stub: unknown key allows.
  - Persist: unknown key denies.
  - Override replacement semantics.
  - Empty override list: stub allow, persist deny.
  - Role normalization: case, trim, dedupe.
  - Unknown role override (persist): dropped; list-empty path enforced.
- **Middleware feature tests**
  - Each grid row above has a test.
  - Capability off denies even for Admin.
  - RA=false returns 404 on `/api/rbac/*`.
  - `require_auth=true` returns 401 for anonymous.
  - 403 denies render custom permission page in SPA.
- **Audit assertions**
  - Deny produces one `RBAC` record with canonical action and non-empty fields; UI label matches map.
  - Unknown key emits one audit entry.
  - Unknown roles in override emits one audit entry.
- **Docs**
  - Update this file if routes/policies expand.
  - No OpenAPI change until Phase-5 diff plan approved.

---

## 10) Implementation notes (non-binding)
- Config keys:
  - `core.rbac.enabled` (bool)
  - `core.rbac.require_auth` (bool)
  - `core.rbac.mode` (`stub|persist`)
  - `core.rbac.policies` (array<string, list<string>>)
  - `core.capabilities.*` (bool)
- Evaluator returns tri-state in persist: `allow|deny|unknown_key` (middleware maps unknown to deny).
- Normalization helper: returns `list<string>` of canonical display names given raw input and catalog.
- **Config precedence**: base config → `/opt/phpgrc/shared/config.php` → test injection. No `.env` in production runtime.

---

## 11) AUTH audit addendum
### Canonical events
- `auth.login.success`, `auth.login.failed`, `auth.logout`
- `auth.break_glass.success`, `auth.break_glass.failed`

### Fields
- `category:"AUTH"`
- `action` as above
- `actor_id`:
  - `"anonymous"` **only when the request provides no identifier** (e.g., empty username/email).
  - `null` when an identifier is present but authentication fails. Do **not** resolve to a user ID on failed attempts.
  - user ULID on successful authentication.
- `entity_type:"user"`
- `entity_id`: user ULID on success; `null` on failed login.
- `occurred_at`, `ip`, `ua`
- `meta`: `{method:"password|oauth|sso", mfa:boolean, reason?:string, identifier?:string}`
  - Include `identifier` only if provided in the request.

### Brute-force controls
- Settings:
  - `core.auth.bruteforce.enabled`: true
  - `core.auth.bruteforce.strategy`: `"ip"` or `"session"` (default `"session"`)
  - `core.auth.bruteforce.window_seconds`: 900
  - `core.auth.bruteforce.max_attempts`: 5
  - `core.auth.session_cookie.name`: `"phpgrc_auth_attempt"`
- Behavior:
  - On first auth attempt, issue the session cookie. Strategy `"session"` counts failures per cookie; `"ip"` counts per IP.
  - After `max_attempts` within `window_seconds`, impose a temporary lock.
  - Lock response default: `429 Too Many Requests` with retry-after; configurable to `403`.
  - Audit every failed attempt (`auth.login.failed`) and every lock event (`auth.login.locked` with `meta{strategy, attempts, window}`).
  - Cookie is client-dropped upon first attempt as per strategy requirement.
- Rationale:
  - Avoid user lookup and ID resolution on failed attempts to reduce account-enumeration signals. All failed logins keep `entity_id=null`.

---

## 12) Acceptance criteria
- Check grid passes in CI.
- Static analysis passes at PHPStan Level 9 and Psalm clean.
- No change to OpenAPI 0.4.6.
- Audit entries present and non-empty for error paths defined.
