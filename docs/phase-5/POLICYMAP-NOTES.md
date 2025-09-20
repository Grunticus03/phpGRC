# PolicyMap: semantics, overrides, and middleware grid

## Purpose
Map policy keys → allowed roles. Enforce fine-grained access in persist mode. Support ops overrides without touching code.

## Normalization
- Role tokens: trim → collapse whitespace → replace spaces with `_` → lowercase.
- Regex: `^[\p{L}\p{N}_-]{2,64}$`.
- Examples: `Risk Manager` → `risk_manager`, `Admin` → `admin`.

## Enforcement flow (middleware)
Order is fixed.

1) Capability gate  
   - If route has `capability` and `config('core.capabilities.<key>')` is falsy ⇒ `403 CAPABILITY_DISABLED`.

2) Auth gate  
   - If `core.rbac.require_auth=true` and no user ⇒ `401`.

3) Role gate  
   - If route has `roles` and user lacks any ⇒ `403`.

4) Policy gate  
   - If route has `policy`:
     - **stub mode**: advisory only ⇒ allow.
     - **persist mode**: enforce.
       - Unknown policy key ⇒ deny (`403`).
       - Known policy key but none of user’s normalized role tokens in PolicyMap set ⇒ deny (`403`).
       - Else allow.

Notes:
- “Policy enforces even if roles pass” in persist mode.
- RBAC disabled (`core.rbac.enabled=false`) ⇒ skip gates 3–4.

## Overrides
Set `core.rbac.policies` in overlay config.

```php
// config overlay
'core' => [
  'rbac' => [
    'policies' => [
      'core.settings.manage'   => ['Admin'],
      'core.audit.view'        => ['Admin', 'Auditor'],
      'rbac.user_roles.manage' => ['Admin'],
      // override example
      'core.exports.generate'  => ['Admin', 'Risk Manager'],
    ],
  ],
],
```

Unknown roles in overrides:
- Persist mode: audit `RBAC rbac.policy.override.unknown_role` with `meta.unknown_roles=[...]`.
- Stub mode: no audit.

## Middleware check grid
Legend: RA=require_auth, AU=authenticated user, RR=route roles, RP=route policy, CAP=capability flag

| Mode   | CAP | RA | AU | RR present + match | RP present + result | HTTP |
|--------|-----|----|----|--------------------|---------------------|------|
| stub   | off |  F |  F | N/A                | N/A                 | 200  |
| stub   | off |  T |  F | N/A                | N/A                 | 401  |
| stub   | on  |  * |  * | *                  | *                   | 403  |
| stub   | off |  T |  T | present + fail     | any                 | 403  |
| stub   | off |  T |  T | present + pass     | any                 | 200  |
| stub   | off |  T |  T | absent             | present + any       | 200  |
| persist| on  |  * |  * | *                  | *                   | 403  |
| persist| off |  T |  F | any                | any                 | 401  |
| persist| off |  T |  T | present + fail     | any                 | 403  |
| persist| off |  T |  T | present + pass     | present + deny/unknown | 403 |
| persist| off |  T |  T | present + pass     | present + allow     | 200  |
| persist| off |  T |  T | absent             | present + deny/unknown | 403 |
| persist| off |  T |  T | absent             | present + allow     | 200  |
| persist| off |  T |  T | absent             | absent              | 200  |

## Audit tagging (Phase-5 target)
- Add per-request attribute already provided: `rbac_policy_allowed` boolean.
- Emit middleware audit on denies:
  - `RBAC rbac.deny.capability`
  - `RBAC rbac.deny.unauthenticated`
  - `RBAC rbac.deny.role_mismatch`
  - `RBAC rbac.deny.policy`
  - Include route, policy key, matched roles, user id, ip, ua.
