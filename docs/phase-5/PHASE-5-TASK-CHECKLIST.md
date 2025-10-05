# Phase 5 Task Checklist

Status: Ready to tag  
Contract: OpenAPI 0.4.7 (no breaking changes)  
Gates: PHPStan lvl 9, Psalm clean, PHPUnit, **Redocly lint**, **openapi-diff**

_Last updated: 2025-09-28_

---

## 0) Ground rules
- No breaking OpenAPI changes. Additive only.
- RBAC policy **enforces** in persist mode, even if roles pass.
- Runtime reads via `config()`. `.env` only at bootstrap.
- CI must stay green at each step.
- **New (session):** No `.env` usage for runtime toggles; all configurable settings (other than DB connection and secrets) live in **DB-backed settings**. ENV remains only for bootstrap/infra knobs (e.g., DB DSN, `APP_KEY`, and API throttle ENV overrides by design — see deprecations/precedence notes).

---

## 1) RBAC middleware: final enforcement
- [x] Confirm capability gate returns `403 CAPABILITY_DISABLED`.
- [x] Confirm auth gate returns `401` when `core.rbac.require_auth=true`.
- [x] Confirm role gate compares normalized tokens.
- [x] Confirm policy gate denies on unknown key in persist mode.
- [x] Tag `rbac_policy_allowed` request attribute.
- [x] Unit tests: role-only, policy-only, both, unknown-policy (core), capability-off (separate feature area).
  - Note: Persist/stub parity verified via feature tests.

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
- [x] One audit row per deny (no duplicates).
  - Note: Web UI label map for `rbac.deny.*` integrated and tested.

**Tests**
- [x] Assert one audit row per deny (see `RbacDenyAuditsTest`).

---

## 3) PolicyMap behavior
- [x] Normalization: trim → collapse spaces → `_` → lowercase. Regex `^[\p{L}\p{N}_-]{2,64}$`.
- [x] `defaults()` reads `core.rbac.policies`.
- [x] `roleCatalog()` uses DB in persist if table exists, else config.
- [x] Unknown roles in overrides (persist): audit `rbac.policy.override.unknown_role` once per policy per boot.
- [x] Cache fingerprint includes policies, mode, persistence, catalog.
  - Note: Unknown-role audits verified with `meta.unknown_roles` content.

**Tests**
- [x] Override denies when user lacks mapped role.
- [x] Unknown-role audit emitted with `meta.unknown_roles`.

---

## 4) Capability mapping
- [x] Map `core.audit.export`, `core.evidence.upload` to explicit capability gates where applicable.
- [x] Extend later when non-admin grants are approved (placeholder test proves wildcard works).

**Tests**
- [x] Capability disabled returns `403 CAPABILITY_DISABLED` (`AuditExportCapabilityTest`, `EvidenceUploadCapabilityTest`).

---

## 5) KPIs endpoint
- [x] Route: `GET /api/dashboard/kpis`.
  - Note: Alias route `GET /api/metrics/dashboard` added; same controller/action and contract.
- [x] Query params: `from`, `to`, `tz`, `granularity=day` (week/month deferred).
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
    "window": {"rbac_days":7,"fresh_days":30, "tz":"UTC", "granularity":"day", "from?":"ISO-8601", "to?":"ISO-8601"}
  }
}
```
*(Note: `from`/`to`/`tz`/`granularity` present only when range params supplied; server clamps inclusive day span to `[1..365]`.)*

**Tests**
- [x] Validation errors for bad params (`DashboardKpisValidationTest`).
- [x] Deterministic series using seeded data (`DashboardKpisComputationTest`).
- [x] RBAC deny without `core.metrics.view` (`DashboardKpisAuthTest`).

**UI**
- [x] Controls for `rbac_days` and `days` wired to query.
- [x] Sparkline for daily RBAC denies series in card.
  - Note: Frontend pulls defaults from `/api/admin/settings` effective config; clamps to `[1..365]` client-side as well.

---

## 6) Auth rate limiting
- [x] Config: target=`ip|session`, attempts=N (default 5), lock window (seconds).
- [x] Drop session cookie on first auth attempt (for session-target mode).
- [x] Deny emits `AUTH` audit:
  - [x] Failed attempt: `auth.login.failed` (identifier semantics preserved).
  - [x] Lock event: `auth.login.locked`.
- [x] Toggle via config for tests.
  - Note: End-to-end tests cover both strategies’ lock path and audit emissions.
- [x] **Tests done this session**: lock after N; `Retry-After` present; unlock after window; cookie issuance on session strategy.

---

## 6a) Generic API rate limiting (finalized this session)
- [x] Middleware `GenericRateLimit` implemented (`user|ip|session`), attach via `Route::defaults(['throttle'=>...])`.
- [x] Global knobs: `core.api.throttle.enabled|strategy|window_seconds|max_requests`; ENV mapping `CORE_API_THROTTLE_*`.
- [x] **Precedence:** ENV wins; DB entries for `core.api.throttle.*` are ignored by the overlay.
- [x] Unified 429 JSON envelope in `Exceptions\Handler` with `Retry-After` and `X-RateLimit-*` headers.
- [x] Replace legacy `MetricsThrottle` on metrics routes; keep `/auth/login` on `BruteForceGuard`.
- [x] OpenAPI: `components.responses.RateLimited` and 429 references added to throttled endpoints.
- [x] `/health/fingerprint` includes `summary.api_throttle:{enabled,strategy,window_seconds,max_requests}`.

**Acceptance (session)**
- [x] ENV knobs override DB/config for `core.api.throttle.*`.
- [x] `/health/fingerprint` response matches OpenAPI schema.
- [x] OpenAPI lints clean; 429 headers documented.
- [x] PHPUnit remains green.
- [x] Feature tests assert `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining` on **200** and **429** for one user-route and one ip-route.

---

## 7) Role-management UX constraints (API side)
- [x] Validation rule: role names 2–64, Unicode letters/digits/`-`/`_`, no whitespace.
- [x] Normalize to lowercase on store; case-insensitive comparison.
- [ ] All roles editable and deletable. No reserved names in API. *(Deferred)*
- [ ] Search endpoint accepts multi-field query (name/email/etc.). *(Deferred — role catalog is small; user search scoped separately in §15.)*

**Tests**
- [x] Mixed case and extra spaces accepted and normalized.
- [x] >64 chars rejected. Duplicate after normalization rejected.

---

## 7a) **Admin Users Management (beta) — added this session**
**API**
- [x] `GET /api/admin/users` (paged search: `q`, `page`, `per_page` — clamps `[1..500]`).
- [x] `POST /api/admin/users` (create).
- [x] `PATCH /api/admin/users/{id}` (update).  
  - Fields: `name`, `email`, optional `password` (min 8), optional `roles` (ids or names; normalized + deduped).
- [x] `DELETE /api/admin/users/{id}` (idempotent delete).  
- [x] Validation requests: `UserStoreRequest`, `UserUpdateRequest` with strict messages and normalized errors.
- [x] UsersController returns minimal shape for list/single: `{id, name, email, roles?}`.

**RBAC**
- [x] Protected by Admin role + `core.users.manage` policy (create/update/delete) and `core.users.view` (list/get).

**Frontend**
- [x] New Admin → **Users** page.
- [x] CRUD: create, edit (incl. password reset), delete, inline role assignment.
- [x] Accessibility: labelled controls; announce errors; keyboard focus management.
- [x] Hardening: optimistic UI only after 2xx; errors surface in toast/banner.

**Quality gates**
- [x] PHPStan/Psalm clean.
- [x] Vitest component happy path; API client overloads (`apiPost`/`apiPatch`) typed.
- [x] E2E smoke covers create→edit→delete cycle.

**Deprecations (notes)**
- None introduced; endpoints are additive. Policy gates intentionally strict to match Phase-5 security posture.

---

## 8) Documentation
- [x] Update `docs/phase-5/PHASE-5-POLICYMAP-NOTES.md` with final semantics.
- [x] Update `docs/phase-5/PHASE-5-DASHBOARDS.md` with confirmed KPIs and queries.
- [x] Update `docs/phase-5/PHASE-5-DASHBOARDS-AND-REPORTS.md` with contract.
- [x] Keep `docs/phase-5/PHASE-5-KICKOFF.md` in sync.
- [x] Add `docs/OPS.md` runbook.
  - Note: Added Apache vhost guidance, FPM handoff, and `/api` Alias/Rewrite notes.
- [x] OpenAPI: `x-logo.url` corrected to `/api/images/...`; `servers: [{url:"/api"}]` documented.
- [x] **New docs (this session)**: 429 error schema + headers; Redoc `security` array shape gotcha.
- [x] Release notes updated: deprecate `MetricsThrottle`, standardize headers, document throttle knobs and precedence.
- [x] **Add (follow-up)**: Document **Admin Users Management (beta)** endpoints and UI flow (next doc PR).

---

## 9) OpenAPI and quality gates
- [x] **Redocly** lint clean.
- [x] openapi-diff vs 0.4.7 baseline captured: no breaking changes.
- [x] PHPStan lvl 9: no new issues.
- [x] Psalm: no new issues.
- [x] PHPUnit: all suites green.
- **Note:** Spec component `SettingsChange` is currently unused; harmless placeholder for upcoming settings audit. CI uses Redocly lint; Spectral allowlisting not required.
- [x] **Added**: 429 `RateLimited` response and header docs validated by lint & UI render.

---

## 10) Release hygiene
- [x] ROADMAP Phase-5 progress updated.
- [x] SESSION-LOG entry.
- [x] Rollback notes: set `CORE_RBAC_MODE=stub` and/or disable capabilities.
- [x] **Rate limiting rollback**: `CORE_API_THROTTLE_ENABLED=false` restores previous behavior; `/auth/login` brute-force guard remains independent.

---

## 11) Settings persistence (DB-only) — NEW
- [x] Migration: `core_settings` table (string PK `key`, `value` JSON string, `type`, timestamps).
- [x] Model: `App\Models\Setting` (non-incrementing string PK).
- [x] Provider: `SettingsServiceProvider` loads overrides from DB at boot (no `.env` for app settings).
- [x] Service: `SettingsService` (effectiveConfig, apply, diff/changes audit).
- [x] Controller: `SettingsController@index|update` accepts spec + legacy shape; `apply` flag; stub-only mode honored.
- [x] Request: `UpdateSettingsRequest` validation hardened; legacy shape flatten; error shape grouped.
- [x] Metrics moved to DB: `core.metrics.cache_ttl_seconds`, `core.metrics.evidence_freshness.days`, `core.metrics.rbac_denies.window_days`.
- [x] Frontend: Admin Settings uses `GET /api/admin/settings` and `PUT /api/admin/settings`; tests updated from POST→PUT.
- [x] Tests: persistence suite (`SettingsPersistenceTest`) covers set/unset/partial updates; validation tests updated.
  - Note: No default seeds in DB by design; DB is system of record for settings (except DB connection).
- [x] **Added**: Persistable API throttle knobs (`core.api.throttle.*`).

**Deprecations (kept with notes)**
- ENV defaults for application behavior are **deprecated** in favor of DB persistence. ENV remains only for bootstrap (DB DSN, `APP_KEY`) and **explicit** API throttle overrides for ops safety.

---

## 12) Infra: Apache + PHP-FPM wiring — NEW
- [x] Dedicated vhost with HTTPS, HSTS, and `/api` → Laravel public via **Alias**.
- [x] SPA fallback rewrite inside `<Directory /web/dist>` (serves `index.html` for deep links).
- [x] Prefer `AllowOverride None` with vhost-managed rewrites (or `AllowOverride All` if using `.htaccess`).
- [x] Verified routes reachable via Apache (`/api/health`, `/api/dashboard/kpis`).
- [x] Cleared config/route caches after deploy; default cache driver set to `file` where DB cache table absent.

---

## 13) Post-merge smoke — NEW
- [x] Admin Settings PUT persists overrides; DB rows visible in `core_settings`.
- [x] KPIs respond with identical shapes at both routes (`/api/dashboard/kpis`, `/api/metrics/dashboard`).
- [x] Web SPA dashboard reads KPIs successfully and renders tiles/sparkline.
- [x] RBAC `require_auth` flag behavior validated behind Apache.
- [x] Redoc logo loads from `/api/images/...`.
- [x] Admin nav + Admin index link “API Docs” to `/api/docs`; no embedded Redoc in SPA.
- [x] **New**: Rate-limited routes return 429 envelope + headers; Redoc page renders without `security.map` error.

---

## 14) OpenAPI serve headers & controller hardening — NEW
- [x] `/api/openapi.yaml` served with exact `Content-Type: application/yaml` (no charset).
- [x] **Caching**: `ETag: "sha256:<hex>"`, `Cache-Control: no-store, max-age=0`, `X-Content-Type-Options: nosniff`.
- [x] **Optional headers** when file exists: `Last-Modified` (UTC RFC 7231), `Vary: Accept-Encoding`.
- [x] PHPUnit: `OpenApiSpecTest::test_yaml_served_with_expected_headers_and_content` passing.
- [x] Static analysis: no PHPStan/Psalm violations in `OpenApiController`.
- [x] Serve `/api/openapi.json` with `application/json` and runtime YAML→JSON conversion; parity tests pass (`OpenApiAugmentationTest`).
- [x] **Added**: Ensure top-level `security` is an array to keep Redoc happy.

**Deprecations (kept with notes)**
- None — older ad-hoc OpenAPI serving paths are **deprecated** in favor of the hardened controller but remain aliased for backward-compat until Phase 6.

---

## 15) RBAC user search pagination + default per-page — NEW
- [x] Endpoint `/api/rbac/users/search` uses `page`/`per_page` and stable `id` ordering.
- [x] Server clamps `per_page` to `[1..500]`.
- [x] Default `per_page` read from DB setting `core.rbac.user_search.default_per_page` (default 50).
- [x] Admin Settings UI knob added under RBAC.
- [x] Web UI (Admin → User Roles) adopts paged search and handles `meta.total`/`total_pages`.
- [x] Docs: Redoc paged examples and auth header notes updated. (merged)

**Tests**
- [x] PHPUnit: controller default-per-page and clamping.
- [x] Vitest: Settings knob roundtrip; UserRoles flows (lookup, attach, detach, replace, ROLE_NOT_FOUND surface).

---

## 16) OpenAPI augmentation (runtime injection) — NEW
- [x] Inject standard responses where missing:
  - [x] `401` → `#/components/responses/Unauthenticated` on protected endpoints
  - [x] `403` → `#/components/responses/Forbidden` on RBAC/capability-guarded routes
  - [x] `422` → `#/components/responses/ValidationFailed` on endpoints with validation paths
- [x] Apply to both `/openapi.yaml` (served YAML) and `/openapi.json` (converted JSON).
- [x] Tests validate presence and YAML mutation (`OpenApiAugmentationTest`). 
- [x] **Added**: `429` → `#/components/responses/RateLimited` on throttled endpoints.

---

## 17) Web UI bootstrap & navigation — NEW (this session)
- [x] `AppLayout` bootstraps `require_auth` from `/api/health/fingerprint`; probes `/api/auth/me` only when required.
- [x] Conditional redirect to `/auth/login` only when `require_auth=true` **and** not authenticated.
- [x] Navbar renders consistently once bootstrap completes; skip-to-content link and ARIA attributes included.
- [x] SPA history-mode verified after `npm ci && npm run build`; index fallback works.
- [x] Vitest updated for KPI dashboard mocks and for auth bootstrap behavior.

---

## 18) Frontend API client cleanup — NEW (this session)
- [x] `apiPost`/`apiPatch` overloads with typed responses.
- [x] `authLogin`/`authLogout`/`authMe` helpers.
- [x] `API_BASE` defaults to empty string; router and client generate absolute paths under Apache `/api` alias.
- [x] ESLint and type-check fixes applied (no `any`, stable dependencies, no unused vars).

---

## Execution order (suggested)
1. Middleware deny auditing (#2).
2. KPI endpoint (#5).
3. Rate limiting (#6).
4. Role-management validations (#7).
5. Docs and gates (#8, #9).
6. Release hygiene (#10).
7. Settings persistence & UI roundtrip (#11) — completed during this phase.
8. Infra validation (#12) — completed during this phase.
9. OpenAPI serve headers hardening (#14) — completed during this phase.
10. RBAC user search pagination + default per-page knob (#15) — completed during this phase.
11. OpenAPI augmentation (#16) — completed during this phase.
12. **Generic API rate limiting & 429 normalization** — completed during this session.
13. **Admin Users Management (beta) & Web UI bootstrap/nav** — completed during this session.

---

## Commands (reference)
- [ ] Static analysis: `composer stan` / `composer psalm`
- [ ] Tests: `composer test` (PHPUnit)
- [ ] OpenAPI diff: `openapi-diff docs/api/baseline/openapi-0.4.7.yaml docs/api/openapi.yaml`
- [ ] Redocly: `npx -y @redocly/cli@1.29.0 lint docs/api/openapi.yaml`
- [ ] Cache clears (deploy): `php artisan config:clear && php artisan route:clear && php artisan cache:clear`
