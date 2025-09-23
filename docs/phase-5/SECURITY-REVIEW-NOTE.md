# Security Review — Phase 5

## Scope Reviewed
- Metrics dashboard endpoint (`GET /api/dashboard/kpis`)
- Alias endpoint (`GET /api/metrics/dashboard`) — identical contract
- RBAC middleware deny auditing
- PolicyMap override audit of unknown roles
- Admin Settings persistence flow (DB-backed, no `.env` for non-connection)
- Web audit action labeling

## Findings
1) **Authorization**
   - `core.metrics.view` enforced for both metrics endpoints.
   - Admin Settings routes gated by RBAC roles and policy `core.settings.manage`.
   - Deny outcomes produce a single audit per request.

2) **Input Validation**
   - `days` and `rbac_days` coerced to integers and clamped to **[1,365]** in controller; client sanitizes inputs before submit.
   - Settings writes use `UpdateSettingsRequest` with strict rules and standardized `422 VALIDATION_FAILED` envelope.

3) **Audit Integrity**
   - `rbac.deny.*` consistently labeled.
   - Unknown-role overrides emit `rbac.policy.override.unknown_role` with `meta.unknown_roles` (persist mode only).
   - Settings changes emit `settings.update` with `meta.changes: [{key,old,new,action}]`; no secret values or raw bytes included.

4) **Data Residency**
   - **All non-connection Core settings persist in DB (`core_settings`).** Provider loads overrides at boot. `.env` is bootstrap-only.
   - Metrics defaults read from config only as fallback; DB overrides take precedence.

5) **Privacy**
   - No new PII in audits beyond existing fields. IP/UA optional and unchanged.

6) **Operations**
   - Laravel route caching and Apache rewrite now route `/api/*` to Laravel public index. 404s from Apache are avoided.
   - Cache driver defaults to `file` unless DB cache table exists (prevents test-time table errors).

## Risks & Mitigations
- **Endpoint abuse (metrics):** Admin policy required; reads only; consider per-IP admin rate limit.  
  *Mitigation:* RBAC + policy gate; future throttling recommended.
- **Parameter abuse:** Large or negative windows could stress queries.  
  *Mitigation:* coercion + clamp in controller; UI bounds.
- **Audit noise:** Duplicate deny or settings events.  
  *Mitigation:* one-audit-per-request invariant; settings audits aggregate field-level diffs.
- **Config drift:** `.env` values overriding runtime behavior.  
  *Mitigation:* runtime reads come from DB; provider overlay at boot; CI check for `env(` outside `config/`.

## Recommendations
- Add throttling to admin-only metrics endpoints.
- Extend capability gates coverage for exports/evidence where applicable.
- When adding future metrics params (timezone, granularity), validate and normalize to a safe allowlist.
- Seed minimal baseline rows in `core_settings` on first install to simplify ops, but keep app functional with an empty table.

## Tests Summary
- Backend: KPI computation and window clamping; RBAC auth on metrics; DB-backed settings apply/unset; validation envelope shape.
- Frontend: KPI UI renders with adjustable windows; query propagation; deny label mapping verified.

## Out-of-Scope (kept for visibility)
- Theme/branding security (Phase 5.5): import scrub, MIME validation, SVG sanitization — tracked separately and not part of this review.

