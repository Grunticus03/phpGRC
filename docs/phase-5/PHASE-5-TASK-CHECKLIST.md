# Phase 5 Task Checklist

Status: Active  
Contract: OpenAPI 0.4.6 (no breaking changes)  
Gates: PHPStan lvl 9, Psalm clean, PHPUnit, Spectral, openapi-diff

---

## 0) Ground rules
- No breaking OpenAPI changes. Additive only.
- RBAC policy **enforces** in persist mode, even if roles pass.
- Runtime reads via `config()`. `.env` only at bootstrap.
- CI must stay green at each step.

---

## 1) RBAC middleware: final enforcement
- [ ] Confirm capability gate returns `403 CAPABILITY_DISABLED`.
- [ ] Confirm auth gate returns `401` when `core.rbac.require_auth=true`.
- [ ] Confirm role gate compares normalized tokens.
- [ ] Confirm policy gate denies on unknown key in persist mode.
- [ ] Tag `rbac_policy_allowed` request attribute.
- [ ] Unit tests: role-only, policy-only, both, unknown-policy, capability-off.

**Acceptance**
- [ ] Feature tests cover all branches of the middleware grid.
- [ ] No relies-on-order bugs between gates.

---

## 2) Deny auditing (middleware)
- [ ] Implement audit emits for denies:
  - [ ] `rbac.deny.capability`
  - [ ] `rbac.deny.unauthenticated`
  - [ ] `rbac.deny.role_mismatch`
  - [ ] `rbac.deny.policy`
- [ ] Payload: `route`, `policy` (if present), `user_id|null`, `roles_normalized[]`, `ip`, `ua`, `occurred_at`.
- [ ] Do not emit on allow.

**Tests**
- [ ] Assert one audit row per deny.
- [ ] Assert payload fields constrained and types correct.

---

## 3) PolicyMap behavior
- [x] Normalization: trim → collapse spaces → `_` → lowercase. Regex `^[\p{L}\p{N}_-]{2,64}$`.
- [x] `defaults()` reads `core.rbac.policies`.
- [x] `roleCatalog()` uses DB in persist if table exists, else config.
- [ ] Unknown roles in overrides:
  - [ ] Persist mode only: audit `rbac.policy.override.unknown_role` once per policy per request cycle.
- [ ] Cache fingerprint includes policies, mode, persistence, catalog.

**Tests**
- [ ] Override denies when user lacks mapped role.
- [ ] Unknown-role audit emitted with `meta.unknown_roles`.

---

## 4) Capability mapping
- [ ] Map `core.exports.generate`, `core.audit.export`, `core.evidence.upload` to `admin` wildcard.
- [ ] Extend later when non-admin grants are approved (placeholder test proves wildcard works).

**Tests**
- [ ] Capability disabled returns `403 CAPABILITY_DISABLED`.

---

## 5) KPIs endpoint
- [x] Route: `GET /api/dashboard/kpis`.
- [ ] Query params: `from`, `to`, `tz`, `granularity=day|week|month`.
- [x] RBAC: require `policy=core.metrics.view`.
- [ ] Series computed:
  - [x] Policy denials over time.
  - [x] Evidence intake velocity (+bytes).
  - [ ] Audit volume by category.
  - [ ] MFA coverage (stub or real flag).
  - [ ] Export outcomes (stub-compatible).
- [x] Cursor-safe SQL and indexes where needed.

**Contract**
- [ ] Response shape:
  ```json
  {
    "ok": true,
    "window": {"from":"YYYY-MM-DD","to":"YYYY-MM-DD","tz":"Area/City","granularity":"day"},
    "series": { "...": [...] }
  }
  ```

**Tests**
- [ ] Validation errors for bad params.
- [x] Deterministic series using seeded data.
- [x] RBAC deny without `core.metrics.view`.

---

## 6) Auth rate limiting
- [x] Config: target=`ip|session`, attempts=N (default 5), lock window (seconds).
- [x] Drop session cookie on first auth attempt (for session-target mode).
- [x] Deny emits `AUTH` audit:
  - [x] Failed attempt: `auth.login.failed` with `actor_id="anonymous"` when username missing.
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
- [x] Update `docs/phase-5/POLICYMAP-NOTES.md` with final semantics.
- [x] Update `docs/phase-5/DASHBOARDS.md` with confirmed KPIs and queries.
- [x] Update `docs/phase-5/PHASE-5-PR-CHECKLIST.md`.
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
- [ ] Tests: `phpunit`
- [ ] OpenAPI diff: `openapi-diff old.yaml new.yaml`
- [ ] Spectral: `spectral lint openapi.yaml`
