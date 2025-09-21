# Dashboards and KPIs (shortlist)

## KPIs (v1)
1) **RBAC denies rate** ✅
   - Metric: share of RBAC deny actions over total audited requests.
   - Window: default from `core.metrics.rbac_denies.window_days` (fallback 7), daily buckets.
   - Deny actions: `rbac.deny.capability`, `rbac.deny.unauthenticated`, `rbac.deny.role_mismatch`, `rbac.deny.policy`.
   - Source: `audit_events` (categories `RBAC` and `AUTH` for denominator).

2) **Evidence freshness** ✅
   - Metric: % of evidence items with `updated_at` older than N days.
   - Default N: from `core.metrics.evidence_freshness.days` (fallback 30); slice by MIME.
   - API percent in `[0,1]` (client may render as `%`).
   - Source: `evidence` table.

3) **Policy denials over time**
   - Metric: count of `RBAC rbac.deny.policy` per day.
   - Use: spot misconfig or privilege gaps.
   - Source: `audit_events`.

4) **Evidence intake velocity**
   - Metric: evidence items created per week + 7/30-day moving avg.
   - Use: workload and control cadence.
   - Source: `evidence` table (created_at), optional size sum.

5) **Audit event volume by category**
   - Metric: stacked counts per category (AUTH, RBAC, EXPORTS, EVIDENCE).
   - Use: anomaly detection, ops noise.
   - Source: `audit_events`.

6) **MFA enrollment coverage**
   - Metric: % of active users with TOTP enrolled.
   - Use: control compliance.
   - Source: `users` + `user_mfa` (or equivalent flag).

7) **Export jobs outcomes**
   - Metric: counts by status (pending, running, complete, failed).
   - Use: reliability; detect regressions.
   - Source: `export_jobs` (Phase-5/6 table).

## Minimal queries (sketch)
- RBAC denies rate:
  ~~~sql
  SELECT DATE(occurred_at) d,
         SUM(CASE WHEN action LIKE 'rbac.deny.%' THEN 1 ELSE 0 END) denies,
         COUNT(*) total
  FROM audit_events
  WHERE category IN ('RBAC','AUTH')
    AND occurred_at BETWEEN :from AND :to
  GROUP BY d ORDER BY d;
  ~~~

- Evidence freshness:
  ~~~sql
  SELECT mime, COUNT(*) total,
         SUM(CASE WHEN updated_at < :cutoff THEN 1 ELSE 0 END) stale
  FROM evidence
  GROUP BY mime ORDER BY total DESC;
  ~~~

- Audit by category:
  ~~~sql
  SELECT category, DATE(occurred_at) d, COUNT(*) c
  FROM audit_events
  WHERE occurred_at BETWEEN :from AND :to
  GROUP BY category, d ORDER BY d;
  ~~~

## Contract
- **Internal** `GET /api/dashboard/kpis`
  - RBAC: `policy: core.metrics.view`, `roles:["Admin"]`.
  - Query params:
    - `days` → evidence freshness threshold (clamped 1–365, default from config or 30).
    - `rbac_days` → RBAC denies window (clamped 1–365, default from config or 7).
  - Response:
    - `rbac_denies: {window_days,from,to,denies,total,rate,daily[{date,denies,total,rate}]}`
    - `evidence_freshness: {days,total,stale,percent,by_mime[{mime,total,stale,percent}]}`
  - Timezone: UTC at rest; client renders in local TZ.

## RBAC
- `core.metrics.view` required for KPIs.
- CSV export widgets (future) must respect `capabilities.core.audit.export`.

## UI notes
- Current UI renders two KPI tiles and a by-MIME table.
- Default range: RBAC 7 days; Evidence freshness 30 days.
- Granularity auto from window (day/week/month) when more series are added.

## Performance
- Target ≤ 200ms on 10k `audit_events`.
- Suggested indexes: `(category, occurred_at)`, `(action, occurred_at)`.
