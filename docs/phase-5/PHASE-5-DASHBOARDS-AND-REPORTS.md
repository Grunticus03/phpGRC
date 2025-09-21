# Phase 5 — Dashboards and Reports

## Preamble
- Date: 2025-09-21
- Phase: 5 (active)
- Goal: implement first two KPIs server-side and expose via an internal endpoint without changing OpenAPI.

## Constraints
- OpenAPI 0.4.6 must not change until the diff plan is approved.
- RBAC persist vs stub semantics from Phase 4 remain intact.
- Admin-only access. Render custom 403 on deny.

---

## KPI shortlist (pick 2 to implement first)
1) **Evidence freshness** ✅  
   Definition: percent of evidence items with `updated_at` older than N days.  
   Default N: from config `core.metrics.evidence_freshness.days` (fallback 30).  
   Slice: overall and by MIME type.  
   Output percent in `[0,1]` (UI may render as `%`).  
   Outcome: trend 30/60/90 days.

2) **RBAC denies rate** ✅  
   Definition: share of audited requests producing RBAC deny actions over total audited RBAC/AUTH traffic.  
   Actions counted as denies: `rbac.deny.capability`, `rbac.deny.unauthenticated`, `rbac.deny.role_mismatch`, `rbac.deny.policy`.  
   Window: default from config `core.metrics.rbac_denies.window_days` (fallback 7) with daily buckets.

3) **Audit event volume**  
   Definition: count of audit events per category per day.  
   Categories: `AUTH`, `RBAC`, `EXPORTS`, others present.  
   Use-case: anomaly detection and capacity.

4) **Export success and latency**  
   Definition: success rate of export jobs and median time-to-complete.  
   Window: last 30 days; grouped by export type.

5) **Role distribution**  
   Definition: number of users per role; highlight users with zero roles.  
   Use-case: entitlement hygiene.

**Implemented first two:** (1) Evidence freshness, (2) RBAC denies rate.

---

## Data sources (Phase-4 available)
- `audit_events` (model: `App\Models\AuditEvent`)  
  Fields: `occurred_at`, `category`, `action`, `actor_id|null`, `entity_type`, `entity_id`, `ip|null`, `ua|null`, `meta?`
- `evidence` tables (Phase 4 persisted evidence; `created_at`, `updated_at`, `mime`, `size_bytes|size`)  
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

---

## Data contracts
**Internal endpoint (Phase-5, not in OpenAPI):**
- `GET /api/dashboard/kpis`  
  RBAC: `roles:["Admin"]`, `policy:"core.metrics.view"`.

**Query params:**
- `days` → evidence freshness threshold, clamped to `[1..365]`  
- `rbac_days` → RBAC denies window, clamped to `[1..365]`

Response:
```json
{
  "ok": true,
  "data": {
    "rbac_denies": {
      "window_days": 7,
      "from": "YYYY-MM-DD",
      "to": "YYYY-MM-DD",
      "denies": 0,
      "total": 0,
      "rate": 0.0,
      "daily": [{"date":"YYYY-MM-DD","denies":0,"total":0,"rate":0.0}]
    },
    "evidence_freshness": {
      "days": 30,
      "total": 0,
      "stale": 0,
      "percent": 0.0,
      "by_mime": [{"mime":"application/pdf","total":0,"stale":0,"percent":0.0}]
    }
  },
  "meta": {
    "generated_at": "ISO-8601",
    "window": {"rbac_days":7,"fresh_days":30}
  }
}
```

**Future (post-diff approval):**
- `GET /api/metrics/evidence/freshness?days=30`
- `GET /api/metrics/rbac/denies?window=7d&bucket=day`
- `GET /api/metrics/audit/volume?window=30d&bucket=day`
- `GET /api/metrics/exports/summary?window=30d`
- `GET /api/metrics/roles/distribution`

All metrics endpoints (present/future) are Admin-only and require `core.metrics.view` (or `core.audit.view` if consolidated). Responses use `{ ok:boolean, data:..., meta:{generated_at, window} }`.

---

## UI notes
- RBAC enforced via middleware; unauthenticated → login; forbidden → custom 403.
- Dashboard renders two KPI tiles and a by-MIME table.
- Label map for audit actions: show human-readable text plus code chip.
- Default ranges: RBAC 7d; Evidence freshness days default 30. Add controls later for window selection.

---

## Acceptance criteria (for each KPI)
- Correctness: values match SQL spot-checks on seed data.
- Security: 401/403 paths verified; no data leakage to non-admin roles.
- Performance: each KPI call ≤ 200 ms on 10k-row `audit_events` test data.
- Tests: feature tests for role/policy gates and response shape; unit tests for calculations.
