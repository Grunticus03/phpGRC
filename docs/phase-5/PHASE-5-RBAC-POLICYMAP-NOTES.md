# Phase 5 — RBAC PolicyMap Overrides & Middleware Check Grid

## Instruction Preamble
- Date: 2025-09-19 (updated 2025-09-21)
- Phase: 5 (active)
- Goal: Specify `PolicyMap` override rules and the enforcement grid for `RbacMiddleware`.
- Constraints: Do not change OpenAPI 0.4.7. Keep Phase-4 stub vs persist semantics intact.

---

## 1) Scope
Define:
- Allowed shape and normalization for `core.rbac.policies` overrides.
- Evaluation order in `RbacMiddleware` for capability, auth, roles, policy.
- Stub vs persist behavior.
- Deterministic outcomes for common routes and caller states (check grid).
- Audit logging behavior and UI display conventions.

---

## 1a) Decision snapshot — 2025-09-30
- **Route alignment:** Admin user CRUD routes move to `/api/users` (and `/api/users/{id}` variants) to match the policy catalog; existing controller logic reused.
- **Policy persistence:** Introduce dedicated tables (`policy_roles`, `policy_role_assignments`) seeded with the default matrix when RBAC persistence is enabled; PolicyMap reads from these tables in persist mode and falls back to config otherwise.
- **Route role defaults:** Remove hard-coded `roles` defaults from protected routes so the PolicyMap is the single enforcement source; middleware continues to emit audits on denies.
- **Audit export guard:** `/api/audit/export.csv` continues to require `core.audit.view` plus capability `core.audit.export`; no standalone `core.audit.export` policy key.
- **Policy catalog endpoint:** Keep `/api/rbac/policies/effective` as the canonical map endpoint (add a lightweight alias only if a consumer demands it).
- **Role slugs:** Use `role_risk_manager` consistently for Risk Manager across seeds, config, and PolicyMap.

---

## 2) Terminology
- **Role**: Human display name. Persisted catalog optional. IDs are `role_<slug>`; policy uses display names.
- **Policy**: Named permission key evaluated by `RbacEvaluator` via PolicyMap.
- **Capability**: Boolean feature gate in config (e.g., `core.exports.generate`).
- **Mode**:
  - **stub**: policies allow; unknown policy keys allow.
  - **persist**: policies enforce; unknown policy keys deny (no special audit).

---

## 3) Default PolicyMap (effective base)
```json
{
  "core.settings.manage":  ["role_admin"],
  "core.audit.view":       ["role_admin", "role_auditor", "role_risk_manager"],
  "core.evidence.view":    ["role_admin", "role_auditor", "role_risk_manager", "role_user"],
  "core.evidence.manage":  ["role_admin", "role_risk_manager"],
  "core.exports.generate": ["role_admin", "role_risk_manager"],
  "rbac.roles.manage":     ["role_admin"],
  "rbac.user_roles.manage":["role_admin"],
  "core.metrics.view":     ["role_admin", "role_auditor", "role_risk_manager"]
}
```
Overrides come from `config('core.rbac.policies')`.

---

## 3a) Persistence store (persist mode)
- Tables:
  - `policy_roles(policy PK, label, timestamps)` tracks the catalog of policy keys.
  - `policy_role_assignments(policy, role_id, timestamps)` maps policy keys to normalized role IDs.
- Migration `2025_09_30_000400_create_policy_tables.php` seeds the default baseline matrix (Admin/Auditor/Risk Manager/User coverage).
- In persist mode the effective map comes from these tables; config defaults remain the fallback when assignments are absent.
- Deleting rows from `policy_role_assignments` removes grants for that policy; deleting a `policy_roles` row treats the policy as unknown.

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
   - Replace spaces with `_`; lowercase; allow `^[\p{L}\p{N}_-]{2,64}$`.
   - Deduplicate after normalization.
4. **Unknown roles**:
   - **persist**: drop unknown names; if list becomes empty, rule 2 applies. Audit once per policy as `RBAC` / `rbac.policy.override.unknown_role` with `meta.unknown_roles`.
   - **stub**: accept for logging only; allow outcome unchanged.
5. **Unknown policy keys**:
   - **stub**: allow.
   - **persist**: deny (handled by evaluator). No special audit emitted.
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
Per request. Route may declare `roles: string[]`, `policy: string`, `capability: string`.

1. **RBAC enabled?** `core.rbac.enabled`
   - If `false`: skip role/policy gates. Capability gate may still apply.

2. **Capability gate** (if route declares one)
   - If `config("core.capabilities.$capability") !== true`: **403** with code `CAPABILITY_DISABLED`.

3. **Auth gate** (`core.rbac.require_auth`)
   - If `true` and no user: **401** (AuthenticationException).

4. **Role gate** (if route declares `roles[]`)
   - Any-of match. If user lacks all: **403**.

5. **Policy gate** (if route declares `policy`)
   - **stub**: allow.
   - **persist**:
     - If key missing in effective PolicyMap: **403**.
     - Else allow if user has any role in list; else **403**.

Notes:
- Policy gate enforces even if roles pass (in **persist**).
- Capability can deny even if role/policy would pass.

---

## 7) Deterministic check grid

Legend:
- RA = `core.rbac.enabled`
- AUTH = `core.rbac.require_auth`
- MODE = `core.rbac.mode` (`stub`|`persist`)
- Caller: A0=anonymous, U0=authed no roles, UA=authed Admin, UU=authed Auditor

| Route (example)                              | RA  | AUTH | MODE    | roles[]              | policy                 | capability              | Caller | Result |
|---------------------------------------------|-----|------|---------|----------------------|------------------------|-------------------------|--------|--------|
| GET `/api/audit`                            | T   | T    | persist | —                    | core.audit.view        | —                       | A0     | 401    |
| GET `/api/audit`                            | T   | T    | persist | —                    | core.audit.view        | —                       | U0     | 403    |
| GET `/api/audit`                            | T   | T    | persist | —                    | core.audit.view        | —                       | UU     | 200    |
| GET `/api/audit`                            | T   | F    | stub    | —                    | core.audit.view        | —                       | A0     | 200    |
| POST `/api/admin/settings`                  | T   | T    | persist | —                    | core.settings.manage   | —                       | UA     | 200    |
| POST `/api/admin/settings`                  | T   | T    | persist | —                    | core.settings.manage   | —                       | UU     | 403    |
| POST `/api/evidence`                        | T   | T    | persist | —                    | core.evidence.manage   | —                       | UA     | 200    |
| POST `/api/evidence`                        | T   | T    | persist | —                    | core.evidence.manage   | —                       | UU     | 403    |
| GET `/api/evidence`                         | T   | T    | persist | —                    | core.evidence.view     | —                       | UU     | 200    |
| POST `/api/exports`                         | T   | T    | persist | —                    | core.exports.generate  | core.exports.generate   | UA     | 200    |
| POST `/api/exports` (capability off)        | T   | T    | persist | —                    | core.exports.generate  | core.exports.generate=F | UA     | 403    |
| GET `/api/rbac/roles`                       | T   | T    | persist | —                    | rbac.roles.manage      | —                       | UA     | 200    |
| GET `/api/rbac/roles`                       | T   | T    | persist | —                    | rbac.roles.manage      | —                       | UU     | 403    |
| GET `/api/dashboard/kpis`                   | T   | T    | persist | —                    | core.metrics.view      | —                       | UA     | 200    |
| GET `/api/dashboard/kpis`                   | T   | T    | persist | —                    | core.metrics.view      | —                       | UU     | 403    |
| Any route with unknown policy in **persist** | T   | T    | persist | —                    | unknown.key            | —                       | UA     | 403    |
| Any route when RA = false                   | F   | —    | —       | —                    | —                      | (cap may apply)         | any    | 200/403|

(*) Subject to capability gates if declared.

---

## 8) Audit logging (deny paths)
Exactly **one** RBAC deny audit per denied request.

Canonical category/action:
- `RBAC rbac.deny.capability`
- `RBAC rbac.deny.unauthenticated`
- `RBAC rbac.deny.role_mismatch`
- `RBAC rbac.deny.policy`

Required fields:
- `category:"RBAC"`, `action:non-empty`, `entity_type:"route"`
- `entity_id:"<METHOD> /path"`
- `actor_id` nullable
- `ip`, `ua` nullable
- `meta` includes (when available):
  - `reason: "capability" | "unauthenticated" | "role" | "policy"`
  - `policy`, `capability`
  - `required_roles: string[]`
  - `rbac_mode: "stub" | "persist"`
  - `route_name`, `route_action`
  - `request_id: ULID`

PolicyMap override safety audit (persist mode):
- Unknown roles in overrides: `RBAC rbac.policy.override.unknown_role` with `meta.unknown_roles`.

---

## 9) UI display conventions
- Show human label + canonical code chip. Labels come from deterministic map.
  - Examples:
    - `rbac.deny.unauthenticated` → “Denied: unauthenticated”
    - `rbac.deny.role_mismatch` → “Denied: role check”
    - `rbac.deny.policy` → “Denied: policy check”
- One-audit-per-request invariant ensures no duplicates in tables.

---

## 10) Test checklist (Phase-5 PR gate)
- **Evaluator unit**:
  - Stub: unknown key allows.
  - Persist: unknown key denies.
  - Override replacement semantics and normalization.
  - Unknown role override (persist): dropped + audit.
- **Middleware feature**:
  - Capability off denies Admin (`CAPABILITY_DISABLED` body).
  - `require_auth=true` returns 401 for anonymous.
  - Role mismatch returns 403 and emits `rbac.deny.role_mismatch`.
  - Policy deny returns 403 and emits `rbac.deny.policy`.
  - Exactly one audit per denied request.
- **Docs**: keep this file aligned with emitted action names.

---

## 11) Acceptance criteria
- Grid covered by feature tests; static analysis clean (PHPStan L9, Psalm).
- No OpenAPI changes for Phase-5 KPIs route.
- Deny audits include non-empty `action`, `category`, `entity_id`.
