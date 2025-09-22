# Phase 5 — Dashboards, RBAC Deny Audits, Ops Notes

## Summary
Implements KPI dashboard polish and metrics API hardening. Adds explicit RBAC deny action labels in the web UI. Documents metrics defaults and ops steps.

## Changes
- Web
  - Dashboard KPI controls for `rbac_days` and evidence `days` with clamping to [1,365].
  - Sparkline for RBAC daily denies series.
  - Audit list shows human labels and ARIA text for `rbac.deny.*` actions.
- API
  - `/api/dashboard/kpis` controller clamps `days` and `rbac_days` to [1,365].
  - Emits one audit per denied request; no duplicates.
  - PolicyMap logs `rbac.policy.override.unknown_role` once per policy per boot (persist).
- Docs
  - OpenAPI notes: metrics are internal; config-driven defaults listed.
  - `docs/OPS.md` runbook: enabling metrics and env defaults.
  - Phase-5 checklists updated.

## Defaults (config → env)
- `core.metrics.evidence_freshness.days` ← `CORE_METRICS_EVIDENCE_FRESHNESS_DAYS` default **30**.
- `core.metrics.rbac_denies.window_days` ← `CORE_METRICS_RBAC_DENIES_WINDOW_DAYS` default **7**.

## Security / RBAC
- Admin-only policy: `core.metrics.view` required.
- Deny variants covered: capability, unauthenticated, role_mismatch, policy.
- Accessibility labels added for all mapped actions.

## Tests
- API: defaults, clamping, 401/403 branches, deterministic KPI series.
- Web: KPI render, forbidden message, query-string application, sparkline, action labels.
- PolicyMap: unknown-role override audit emission.

## Migration
- None. No schema changes. No OpenAPI breaking changes.

## Rollback
- Set `CORE_RBAC_MODE=stub` and/or disable capabilities. Web dashboard hides on 403.

## Screenshots
- KPI cards with sparkline.
- Audit list with human-readable deny labels.

