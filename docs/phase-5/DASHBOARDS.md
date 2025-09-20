# Dashboards and KPIs (shortlist)

## KPIs (v1)
1) **Policy denials over time**
   - Metric: count of `RBAC rbac.deny.policy` per day.
   - Use: spot misconfig or privilege gaps.
   - Source: `audit_events` (category=RBAC, action=rbac.deny.policy).

2) **Evidence intake velocity**
   - Metric: evidence items created per week + 7/30-day moving avg.
   - Use: workload and control cadence.
   - Source: `evidence` table (created_at), optional size sum.

3) **Audit event volume by category**
   - Metric: stacked counts per category (AUTH, RBAC, EXPORTS, EVIDENCE).
   - Use: anomaly detection, ops noise.
   - Source: `audit_events`.

4) **MFA enrollment coverage**
   - Metric: % of active users with TOTP enrolled.
   - Use: control compliance.
   - Source: `users` + `user_mfa` (or equivalent flag).

5) **Export jobs outcomes**
   - Metric: counts by status (pending, running, complete, failed).
   - Use: reliability; detect regressions.
   - Source: `export_jobs` (Phase-5/6 table).

## Minimal queries (sketch)
- Policy denials:
  ```sql
  SELECT DATE(occurred_at) d, COUNT(*) c
  FROM audit_events
  WHERE category='RBAC' AND action='rbac.deny.policy'
    AND occurred_at BETWEEN :from AND :to
  GROUP BY d ORDER BY d;
  ```

- Evidence intake:
  ```sql
  SELECT DATE(created_at) d, COUNT(*) c, COALESCE(SUM(size_bytes),0) bytes
  FROM evidence
  WHERE created_at BETWEEN :from AND :to
  GROUP BY d ORDER BY d;
  ```

- Audit by category:
  ```sql
  SELECT category, DATE(occurred_at) d, COUNT(*) c
  FROM audit_events
  WHERE occurred_at BETWEEN :from AND :to
  GROUP BY category, d ORDER BY d;
  ```

- MFA coverage:
  ```sql
  SELECT
    SUM(CASE WHEN u.totp_enabled=1 THEN 1 ELSE 0 END) enrolled,
    COUNT(*) total
  FROM users u
  WHERE u.active=1;
  ```

## Contracts needed
- `GET /api/dashboard/kpis` returning typed series.
- Cursor-friendly endpoints for large audit/evidence sets.
- Timezone handling: UTC at rest, client TZ in view.
- RBAC:
  - `core.metrics.view` for reading KPIs.
  - Deny if `capabilities.core.audit.export=false` for CSV export widgets.

## UI notes
- Default range: last 30 days.
- Granularity auto from window (day/week/month).
- CSV export for each widget, same filters.
