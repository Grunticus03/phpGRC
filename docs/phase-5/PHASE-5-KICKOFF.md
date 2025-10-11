# Phase 5 Kickoff

Status: Complete — 2025-09-28  
API Contract: OpenAPI 0.4.7 (no breaking changes)  
CI Gates: PHPStan lvl 9, Psalm clean, PHPUnit passing, Spectral + openapi-diff required

## Scope

Harden RBAC with enforced policies in persist mode, add deny auditing, and deliver a minimal KPIs API and dashboards. Introduce configurable brute-force protections. Keep Phase-4 routes and contracts stable.

## Objectives

1. **RBAC enforcement**
   - Persist mode: policy **enforces even if roles pass**.
   - Unknown policy key: **deny** (403).
   - Role checks: case-insensitive with normalized tokens.

2. **Middleware audit events**
   - Emit on denies:
     - `rbac.deny.capability`
     - `rbac.deny.unauthenticated`
     - `rbac.deny.role_mismatch`
     - `rbac.deny.policy`
   - Include: route, policy key, matched user roles (normalized), user id or null, IP, UA.

3. **Dashboards/KPIs**
   - Endpoint: `GET /api/dashboard/kpis`.
   - KPIs v1:
     - Policy denials over time
     - Evidence intake velocity
     - Audit event volume by category
     - MFA enrollment coverage
     - Export jobs outcomes (stub-compatible)

4. **Auth rate limiting**
   - Configurable target: IP or session cookie.
   - Session cookie dropped on first auth attempt.
   - Default: temporary lock after 5 failed attempts; admin configurable.

5. **UX hardening for role management**
   - Role names: 2–64 chars, Unicode letters/digits/hyphen/underscore, no whitespace.
   - User may enter any case; **store lowercase**; compare case-insensitively.
   - No reserved roles; names and permissions editable.
   - Search across all fields, not just name/email.

## Non-Goals

- No breaking schema or OpenAPI changes beyond additive or doc-only.
- No full export pipeline beyond Phase-4 stubs.

## Constraints

- Do not break OpenAPI 0.4.7.
- Preserve stub vs persist behavior from Phase-4, except the decided policy precedence.
- Runtime reads use `config()`; `.env` only at bootstrap.
- When RBAC denies on UI routes and module is available, show custom “permission denied” page, not 404. API returns 403.

## Deliverables

- Updated RBAC middleware with policy gate enforcement + deny audits.
- PolicyMap with override handling, normalization, and unknown-role auditing (persist only).
- KPIs endpoint with typed contract and tests.
- Configurable auth rate limiting with tests.
- Docs: PolicyMap notes, dashboards, PR checklist, and this kickoff.

## Technical Notes

### Role token normalization
- Trim → collapse internal whitespace → replace spaces with `_` → lowercase.
- Regex: `^[\p{L}\p{N}_-]{2,64}$`.
- Examples: `Risk Manager` → `risk_manager`, `Admin` → `admin`.

### Capability gate
- Route default `capability` checks `config('core.capabilities.<key>')`.
- If falsy: 403 with code `CAPABILITY_DISABLED`.

### Auth requirement
- `core.rbac.require_auth=true` causes unauthenticated requests to return 401.
- Testing environment may disable `require_auth` as already configured.

### KPIs contract (draft)
`GET /api/dashboard/kpis?from=YYYY-MM-DD&to=YYYY-MM-DD&tz=Area%2FCity&granularity=day|week|month`

Example response:
```json
{
  "ok": true,
  "window": {"from":"2025-08-20","to":"2025-09-19","tz":"America/Chicago","granularity":"day"},
  "series": {
    "rbac_policy_denials": [{"t":"2025-09-01","v":12}],
    "evidence_intake": [{"t":"2025-09-01","count":8,"bytes":1048576}],
    "audit_by_category": [{"t":"2025-09-01","category":"AUTH","v":5}],
    "mfa_coverage": [{"t":"2025-09-19","enrolled":42,"total":50}],
    "export_outcomes": [{"t":"2025-09-01","pending":2,"running":0,"complete":5,"failed":1}]
  }
}
```

### Audit taxonomy (deny paths)
- Actions:
  - `rbac.deny.capability`
  - `rbac.deny.unauthenticated`
  - `rbac.deny.role_mismatch`
  - `rbac.deny.policy`
- Fields: `route`, `policy`, `user_id|null`, `roles_normalized[]`, `ip`, `ua`, `occurred_at`.

## Testing

- RBAC:
  - Capability on/off 403 behavior.
  - `require_auth` 401 behavior.
  - Role gate pass/fail with normalization.
  - Policy gate: unknown ⇒ 403; deny ⇒ 403; allow ⇒ 200; **enforced even when roles pass**.
- Dashboards:
  - Parameter validation.
  - Deterministic series with seeded data.
- Auth rate limiting:
  - IP vs session cookie modes.
  - Lock after N attempts; unlock after window.

## Risks

- Misconfigured overrides can block access; mitigated by audits and KPIs showing denials.
- Policy precedence change is a behavior shift; covered by tests and docs.
- KPI performance on large datasets; mitigate with indices and cursor-friendly queries.

## Milestones

1. **RBAC middleware audits** — code + tests.
2. **KPIs endpoint** — contract + basic series + tests.
3. **Rate limiting** — config + enforcement + tests.
4. **Docs** — finalize and link from README/ROADMAP.

## Rollback

- Feature flags:
  - Temporarily set `CORE_RBAC_MODE=stub` to bypass policy enforcement.
  - Disable specific capabilities via config to shut off features safely.

## Acceptance / DoD

- All CI gates green.
- New tests added and passing.
- Docs updated: POLICYMAP-NOTES, DASHBOARDS, PHASE-5-PR-CHECKLIST, KICKOFF.
- OpenAPI diff shows no breaking changes.
```
