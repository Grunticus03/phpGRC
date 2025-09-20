# Phase 5 — Dashboards and Reports

## Preamble
- Date: 2025-09-19
- Phase: 5 (prep)
- Goal: shortlist KPIs, define data sources, outline contracts. No OpenAPI change yet.

## Constraints
- OpenAPI 0.4.6 must not change until the diff plan is approved.
- RBAC persist vs stub semantics from Phase 4 remain intact.
- Admin-only access. Render custom 403 on deny.

---

## KPI shortlist (pick 2 to implement first)
1) **Evidence freshness**
   - Definition: percent of evidence items with `updated_at` older than N days.
   - Default N: 30.
   - Slice: overall and by MIME type.
   - Outcome: trend 30/60/90 days.

2) **RBAC denies rate**
   - Definition: share of requests producing `RBAC` deny actions over total audited requests.
   - Actions: `rbac.deny.unauthenticated|capability|role|policy|unknown_policy`.
   - Window: last 7 days, with daily buckets.

3) **Audit event volume**
   - Definition: count of audit events per category per day.
   - Categories: `AUTH`, `RBAC`, `EXPORTS`, others present.
   - Use-case: anomaly detection and capacity.

4) **Export success and latency**
   - Definition: success rate of export jobs and median time-to-complete.
   - Window: last 30 days; grouped by export type.

5) **Role distribution**
   - Definition: number of users per role; highlight users with zero roles.
   - Use-case: entitlement hygiene.

**Proposed first two:** (1) Evidence freshness, (2) RBAC denies rate.

---

## Data sources (Phase-4 available)
- `audit_events` (model: `App\Models\AuditEvent`)
  - Fields: `occurred_at`, `category`, `action`, `actor_id|null`, `entity_type`, `entity_id`, `ip|null`, `ua|null`, `meta?`
- `exports` tables (backed by existing export job tracking; reuse controllers `ExportController`,`StatusController`)
- `evidence` tables (Phase 4 persisted evidence; `created_at`, `updated_at`, `mime`, `size`)
- `users`, `roles`, `role_user` (or equivalent pivot)

No “findings” or “control coverage” KPIs are proposed in Phase 5 to avoid new schema.

---

## Query outlines (implementation-agnostic)
- Evidence freshness:
  - `SELECT COUNT(*) AS total, SUM(CASE WHEN updated_at < NOW() - INTERVAL :days DAY THEN 1 ELSE 0 END) AS stale FROM evidence;`
  - Per MIME: add `GROUP BY mime`.
- RBAC denies rate:
  - Denominator: `SELECT COUNT(*) FROM audit_events WHERE category IN ('RBAC','AUTH') AND occurred_at >= :from;`
  - Numerator: `SELECT COUNT(*) FROM audit_events WHERE category='RBAC' AND action LIKE 'rbac.deny.%' AND occurred_at >= :from;`
  - Daily buckets: `DATE_TRUNC('day', occurred_at)` (use DB-specific equivalent).
- Audit event volume:
  - `SELECT DATE_TRUNC('day', occurred_at) AS d, category, COUNT(*) FROM audit_events WHERE occurred_at BETWEEN :from AND :to GROUP BY d, category;`
- Export success/latency:
  - `SELECT type, COUNT(*) AS total, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS ok, PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY (finished_at - started_at)) AS p50 FROM exports WHERE created_at >= :from GROUP BY type;`
- Role distribution:
  - `SELECT r.name, COUNT(ur.user_id) AS users FROM roles r LEFT JOIN role_user ur ON ur.role_id=r.id GROUP BY r.name;`
  - Zero-role users: `SELECT COUNT(*) FROM users u LEFT JOIN role_user ur ON ur.user_id=u.id WHERE ur.user_id IS NULL;`

---

## Data contracts (no new endpoints yet)
- App will compute KPIs server-side via existing repositories/services and expose to the SPA through **existing** endpoints or internal views until the OpenAPI diff is approved.
- When authorized to change OpenAPI, add:
  - `GET /api/metrics/evidence/freshness?days=30`
  - `GET /api/metrics/rbac/denies?window=7d&bucket=day`
  - `GET /api/metrics/audit/volume?window=30d&bucket=day`
  - `GET /api/metrics/exports/summary?window=30d`
  - `GET /api/metrics/roles/distribution`
- All metrics endpoints:
  - Require `roles:["Admin"]` and policy key `core.audit.view` (or dedicated `core.metrics.view` if added later).
  - Return `{ ok:boolean, data:..., meta:{generated_at, window} }`.

---

## UI notes
- RBAC enforced via middleware; unauthenticated → login; forbidden → custom 403.
- Charts: line (trend), bar (categories), single number tiles.
- Label map for audit actions: show human-readable text plus code chip.

---

## Acceptance criteria (for each KPI)
- Correctness: values match SQL spot-checks on seed data.
- Security: 401/403 paths verified; no data leakage to non-admin roles.
- Performance: each KPI call ≤ 200ms on 10k-row `audit_events` test data.
- Tests: feature tests for role/policy gates; unit tests for calculations.
