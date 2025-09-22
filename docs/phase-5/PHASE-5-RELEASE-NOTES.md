# Release Notes — Phase 5

## New
- KPI Dashboard polish
  - Adjustable windows: RBAC window days and evidence stale threshold.
  - Sparkline for daily RBAC denies.
- RBAC Audit UX
  - Human-readable labels and ARIA text for `rbac.deny.*` in Audit UI.

## API
- Internal admin-only endpoint: `GET /api/dashboard/kpis` (unchanged contract).
- Input hardening: clamps `days` and `rbac_days` to **[1,365]**.
- PolicyMap now audits unknown roles in overrides once per policy per boot (persist mode).

## Config Defaults
- Evidence freshness days: **30** via `CORE_METRICS_EVIDENCE_FRESHNESS_DAYS`.
- RBAC denies window days: **7** via `CORE_METRICS_RBAC_DENIES_WINDOW_DAYS`.

## Compatibility
- No breaking OpenAPI changes.
- No migrations.

## Security
- Requires `core.metrics.view` policy (Admin).
- One audit row per deny outcome.

## Ops
- See `docs/OPS.md` for enabling metrics and defaults.
