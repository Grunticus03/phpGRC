# Phase 5 Task Checklist

Status: Active  
Contract: OpenAPI 0.4.6 (no breaking changes)  
Gates: PHPStan lvl 9, Psalm clean, PHPUnit, Spectral, openapi-diff

_Last updated: 2025-09-21_

---

## 0) Ground rules
- No breaking OpenAPI changes. Additive only.
- RBAC policy **enforces** in persist mode, even if roles pass.
- Runtime reads via `config()`. `.env` only at bootstrap.
- CI must stay green at each step.

---

## 1) RBAC middleware: final enforcement
- [ ] Confirm capability gate returns `403 CAPABILITY_DISABLED`.
- [x] Confirm auth gate returns `401` when `core.rbac.require_auth=true`.
- [x] Confirm role gate compares normalized tokens.
- [x] Confirm policy gate denies on unknown key in persist mode.
- [x] Tag `rbac_policy_allowed` request attribute.
- [x] Unit tests: role-only, policy-only, both, unknown-policy (core), capability-off (separate feature area).

**Acceptance**
- [x] Feature tests cover the middleware grid key branches.
- [x] No relies-on-order bugs between gates.

---

## 2) Deny auditing (middleware)
- [x] Implement audit emits for denies:
  - [x] `rbac.deny.capability`
  - [x] `rbac.deny.unauthenticated`
  - [x] `rbac.deny.role_mismatch`
  - [x] `rbac.deny.policy`
- [x] One audit row per deny outcome (no duplicates).

**Tests**
- [x] Assert one audit row per deny (see `RbacDenyAuditsTest`).

---

## 3) PolicyMap behavior
- [x] Normalization: trim → collapse spaces → `_` → lowercase. Regex `^[\p{L}\p{N}_-]{2,64}$`.
- [x] `defaults()` reads `core.rbac.policies`.
- [x] `roleCatalog()` uses DB in persist if table exists, else config.
- [ ] Unknown roles in overrides (persist): audit `rbac.policy.override.unknown_role` once per policy per boot.
- [ ] Cache fingerprint includes policies, mode, persistence, catalog.

**Tests**
- [x] Override denies when user lacks mapped role.
- [ ] Unknown-role audit emitted with `meta.unknown_roles`.

---

## 4) Capability mapping
- [ ] Map `core.exports.generate`, `core.audit.export`, `core.evidence.upload` to explicit capability gates where applicable.
- [ ] Extend later when non-admin grants are approved (placeholder test proves wildcard works).

**Tests**
- [ ] Capability disabled returns `403 CAPABILITY_DISABLED`.

---

## 5) KPIs endpoint
- [x] Route: `GET /api/dashboard/kpis`.
- [ ] Query params: `from`, `to`, `tz`, `granularity=day|week|month`.
- [x] RBAC: require `policy=core.metrics.view` (Admin only).
- [x] Series computed (v1):
  - [x] RBAC denies rate (7d window, daily buckets & totals).
  - [x] Evidence freshness (N-day cutoff, default 30; overall + by MIME).
- [x] Cursor-safe queries / bounded windows for tests.

**Contract (actual, v1)**
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

**Tests**
- [ ] Validation errors for bad params (future when params are added).
- [x] Deterministic series using seeded data (`DashboardKpisComputationTest`).
- [x] RBAC deny without `core.metrics.view` (`DashboardKpisAuthTest`).

---

## 6) Auth rate limiting
- [x] Config: target=`ip|session`, attempts=N (default 5), lock window (seconds).
- [x] Drop session cookie on first auth attempt (for session-target mode).
- [x] Deny emits `AUTH` audit:
  - [x] Failed attempt: `auth.login.failed` (identifier semantics preserved).
  - [x] Lock event: `auth.login.locked`.
- [x] Toggle via config for tests.

**Tests**
- [ ] IP mode: lock after N failures; unlock after window.
- [ ] Session mode: cookie-based counting; cookie issued on first attempt.
- [ ] Success resets counters.

---

## 7) Role-management UX constraints (API side)
- [ ] Validation rule: role names 2–64, Unicode letters/digits/`-`/`_`, no whitespace.
- [ ] Normalize to lowercase on store; case-insensitive comparison.
- [ ] All roles editable and deletable. No reserved names in API.
- [ ] Search endpoint accepts multi-field query (name/email/etc.).

**Tests**
- [ ] Mixed case and extra spaces accepted and normalized.
- [ ] >64 chars rejected. Duplicate after normalization rejected.

---

## 8) Documentation
- [x] Update `docs/phase-5/PHASE-5-POLICYMAP-NOTES.md` with final semantics.
- [x] Update `docs/phase-5/PHASE-5-DASHBOARDS.md` with confirmed KPIs and queries.
- [x] Update `docs/phase-5/PHASE-5-DASHBOARDS-AND-REPORTS.md` with contract.
- [x] Keep `docs/phase-5/PHASE-5-KICKOFF.md` in sync.

---

## 9) OpenAPI and quality gates
- [x] Spectral lint clean.
- [x] openapi-diff vs 0.4.6: no breaking changes.
- [x] PHPStan lvl 9: no new issues.
- [x] Psalm: no new issues.
- [x] PHPUnit: all suites green.

---

## 10) Release hygiene
- [x] ROADMAP Phase-5 progress updated.
- [x] SESSION-LOG entry.
- [ ] Tag CI pipeline with artifact fingerprint.
- [x] Rollback notes: set `CORE_RBAC_MODE=stub` and/or disable capabilities.

---

## Execution order (suggested)
1. Middleware deny auditing (#2).
2. KPI endpoint (#5).
3. Rate limiting (#6).
4. Role-management validations (#7).
5. Docs and gates (#8, #9).
6. Release hygiene (#10).

---

## Owner matrix (fill-in)
- [ ] RBAC middleware & audits: Owner ___
- [ ] KPIs API: Owner ___
- [ ] Rate limiting: Owner ___
- [ ] Role UX validations: Owner ___
- [ ] Docs & contracts: Owner ___
- [ ] QA & CI gates: Owner ___

---

## Commands (reference)
- [ ] Static analysis: `composer stan` / `composer psalm`
- [ ] Tests: `composer test` (PHPUnit)
- [ ] OpenAPI diff: `openapi-diff old.yaml new.yaml`
- [ ] Spectral: `spectral lint openapi.yaml`
