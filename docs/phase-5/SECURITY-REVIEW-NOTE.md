# Security Review — Phase 5

## Scope Reviewed
- Metrics dashboard endpoint (`/api/dashboard/kpis`)
- RBAC middleware deny auditing
- PolicyMap override audit of unknown roles
- Web audit action labeling

## Findings
1) **Authorization**
   - `core.metrics.view` enforced for `/api/dashboard/kpis`.
   - Deny outcomes produce a single audit per request.
2) **Input Validation**
   - `days`, `rbac_days` clamped to [1,365] in controller and sanitized in web client.
   - Negative-path tests cover invalid input and 403/401 branches.
3) **Audit Integrity**
   - `rbac.deny.*` consistently labeled.
   - Unknown-role overrides emit `rbac.policy.override.unknown_role` with `meta.unknown_roles` (persist only).
4) **Privacy**
   - No PII added to audits beyond existing fields. IP/UA optional and unchanged.
5) **Operations**
   - Defaults sourced from config with env fallbacks. Documented in OpenAPI notes and `docs/OPS.md`.

## Risks & Mitigations
- **Abuse of dashboard endpoint**: Policy required; rate is bounded by simple GET. Mitigated by policy and app auth.
- **Param abuse**: Clamping prevents large or negative windows.
- **Audit noise**: One-audit-per-request invariant preserved; unknown-role audit fires once per policy per boot.

## Recommendations
- Consider rate limiting for admin endpoints (future).
- Expand capability gates coverage for exports/evidence (Phase 5.5+).
- Validate timezone and granularity when those params are introduced.

## Tests Summary
- Backend: KPI computation, clamping, RBAC auth.
- Frontend: KPI UI, sparkline render, query propagation, deny labels.
