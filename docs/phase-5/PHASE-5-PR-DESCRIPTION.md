# Phase 5 — Dashboards, RBAC Deny Audits, Ops Notes

## Summary
Implements KPI dashboard polish and metrics API hardening. Adds explicit RBAC deny action labels in the web UI. Documents metrics defaults and ops steps.
**Additions this PR:** DB-backed default page size for RBAC user search with Admin Settings knob; frontend adoption of paged user search; controller reads setting and clamps.

## Changes
- Web
  - Dashboard KPI controls for `rbac_days` and evidence `days` with clamping to [1,365].
  - Sparkline for RBAC daily denies series.
  - Audit list shows human labels and ARIA text for `rbac.deny.*` actions.
  - **Admin Settings**: new numeric input under RBAC for `User search default per-page` (1–500, default 50).
  - **Admin → User Roles**: user lookup UI adopts paged `/rbac/users/search` contract and handles `meta.total`/`total_pages`.
- API
  - `/api/dashboard/kpis` controller clamps `days` and `rbac_days` to [1,365].
  - Emits one audit per denied request; no duplicates.
  - PolicyMap logs `rbac.policy.override.unknown_role` once per policy per boot (persist).
  - **RBAC user search**: controller reads `core.rbac.user_search.default_per_page` when `per_page` absent; clamps to [1,500]; stable `id` ordering.
- Docs
  - OpenAPI notes: metrics are internal; config-driven defaults listed.
  - `docs/OPS.md` runbook: enabling metrics and env defaults.
  - Phase-5 checklists updated.
  - **RBAC user search**: add Redoc snippet with paged example and auth header. (If not yet merged, this remains an open doc task.)

## Defaults (config → env)
- `core.metrics.evidence_freshness.days` ← `CORE_METRICS_EVIDENCE_FRESHNESS_DAYS` default **30**.
- `core.metrics.rbac_denies.window_days` ← `CORE_METRICS_RBAC_DENIES_WINDOW_DAYS` default **7**.

## Settings (DB-backed)
- `core.rbac.user_search.default_per_page` default **50**. Range **1–500**. Used when `per_page` query param is omitted.

## Security / RBAC
- Admin-only policy: `core.metrics.view` required.
- Deny variants covered: capability, unauthenticated, role_mismatch, policy.
- Accessibility labels added for all mapped actions.
- **RBAC user search**: gated by existing RBAC stack; recommends `require_auth=true` in prod overlays.

## Tests
- API: defaults, clamping, 401/403 branches, deterministic KPI series.
- Web: KPI render, forbidden message, query-string application, sparkline, action labels.
- PolicyMap: unknown-role override audit emission.
- **New**: PHPUnit and Vitest cover user search default-per-page from settings and UI flows.

## Migration
- None. No schema changes. No OpenAPI breaking changes.

## Rollback
- Set `CORE_RBAC_MODE=stub` and/or disable capabilities. Web dashboard hides on 403.
- Adjust `core.rbac.user_search.default_per_page` in DB if needed; server still clamps to `[1..500]`.

## Screenshots
- KPI cards with sparkline.
- Audit list with human-readable deny labels.
- Admin Settings RBAC knob for user search page size. (optional)
