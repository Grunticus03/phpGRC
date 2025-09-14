# /docs/PHASE-4-TASK-BREAKDOWN.md

# Phase 4 — Core App Usable Detailed Task Breakdown

## Instruction Preamble
- Date 2025-09-13
- Phase 4
- Goal Decompose Phase 4 deliverables into detailed, sequential tasks to guide incremental scaffolding, enforcement, and persistence work.
- Constraints
  - No scope outside Charter/Backlog.
  - Each increment must pass CI guardrails.
  - Stubs advance to enforcement/persistence gradually.
  - Full traceability to Backlog IDs (CORE-003, CORE-004, CORE-006, CORE-007, CORE-008, CORE-010).
- Last updated: 2025-09-13

---

## Task Checklist by Feature Area

### 1. Settings UI (CORE-003 expansion)
- [x] Extend `Admin/SettingsController.php` with echo + validation stubs
- [x] Validation rules + unified 422 envelope
- [x] Extend SPA `/web/src/routes/admin/Settings.tsx`
- [x] Add unit tests (`SettingsControllerValidationTest`)
- [x] Document payloads and error codes in `PHASE-4-SPEC.md`

### 2. Audit Trail (CORE-006)
- [x] Persisted `audit_events` table + model
- [x] Settings update listener writes audit rows
- [x] `AuditLogger` helper with typed attributes
- [x] Stub pagination + cursor semantics aligned with tests
- [x] Filters on list (category/action/date range + ids/ip) — persisted path
- [x] Retention job honoring `core.audit.retention_days` (CLI + scheduler clamp)
- [x] CSV export with exact `Content-Type: text/csv` header

### 3. Evidence (CORE-007)
- [x] Persist evidence metadata + bytes in DB (`evidence.bytes`; LONGBLOB on MySQL)
- [x] Size/mime validation
- [x] SHA-256 compute + `ETag` and checksum headers
- [x] Pagination on list
- [x] Filtering on list
- [x] Hash verification on download (`?sha256=` → 412)
- [x] Conditional GET with `If-None-Match` → 304

### 4. Exports (CORE-008)
- [x] Create job endpoints (preferred + legacy)
- [x] Status + download endpoints
- [x] Capability gate `core.exports.generate`
- [x] E2E tests green (CSV/JSON/PDF)
- [x] Background queue path (GenerateExport + artifact persistence)

### 5. Avatars (CORE-010)
- [x] Upload endpoint scaffold
- [x] Transcode to WEBP in worker
- [x] Serve resized variants

### 6. RBAC Enforcement + Catalog (CORE-004)
- [x] `RbacMiddleware` enforces when `core.rbac.enabled=true`
- [x] Request attribute `rbac_enabled` for tests
- [x] Role catalog endpoints (index/store) with slug IDs (`role_<slug>`)
- [x] User–role mapping endpoints (show/replace/attach/detach)
- [x] DB-backed checks via `User::hasAnyRole(...)`
- [x] CI tests for enforcement and audit
- [x] UI for role management (`/admin/roles`)
- [x] User–role assignment UI (read, attach, detach, replace)

### 7. RBAC Audit
- [x] Canonical `rbac.role.created` and `rbac.user_role.{attached,detached,replaced}`
- [x] Legacy aliases `role.{attach,detach,replace}`
- [x] Audit list filters for category `RBAC`
- [x] Export audit events as CSV

### 8. Fine-grained RBAC Policies (CORE-004)
- [x] `PolicyMap` defaults and override support via `core.rbac.policies`
- [x] `RbacEvaluator` with stub/persist semantics
- [x] Enforce `policy` route defaults in `RbacMiddleware`
- [x] Tests for policy-only routes and role–policy mismatch
- [x] Docs updated (`PHASE-4-SPEC.md`)

### 9. Bugfix — Complete Setup Wizard (CORE-001 catch-up)
- [x] Add controllers: `SetupStatusController`, `DbController`, `AppKeyController`, `SchemaController`, `AdminController`, `AdminMfaController`, `SmtpController`, `IdpController`, `BrandingController`, `FinishController`
- [x] Add requests for validation per step
- [x] Add `SetupGuard` middleware to block steps when disabled by config
- [x] Implement `ConfigFileWriter` with atomic write to `core.setup.shared_config_path`
- [x] Wire `/api/setup/*` routes and tests
- [x] Update docs and error taxonomy

---

## Session/Quality Gates
- [x] Psalm/PHPStan/Pint clean
- [x] PHPUnit green in CI
- [x] Contracts frozen in `PHASE-4-SPEC.md` for delivered areas
- [x] CI: Upload artifacts (`api/junit.xml`, `api/storage/logs/*.log`, `web/dist`)
- [x] UX: Replace free-text Audit category with dropdown enum; normalize case
- [x] UX: Surface 422 field-level errors inline on Settings forms
- [ ] UX pass on admin screens (Phase 5)
- [ ] CI: Protect `main` with required jobs (`openapi`, `openapi_breaking`, `api`, `web`) and linear history
- [ ] CI: Add ParaTest and run with CPU parallelism
- [ ] CI: Add coverage (`--coverage-clover coverage.xml`) and upload/report; include vitest coverage/artifacts
- [ ] CI: Add `composer audit` and `npm audit --audit-level=high`
- [ ] CI: Add Dependabot (Composer, npm, GitHub Actions)
- [ ] CI: Add `actionlint` step to lint workflow YAML
- [ ] CI: Add PHP matrix (8.2, 8.3); optional MySQL integration job
- [ ] QA: Raise PHPStan level one notch; fix violations or baseline deltas
- [ ] QA: Raise Psalm level/config; keep threads; stabilize baseline
- [ ] QA: Enforce Pint/PHP-CS-Fixer ruleset in CI
- [ ] QA: Add ESLint + `tsc --noEmit` to `web` job; add Prettier check
- [ ] Config: Implement early boot merge of `/opt/phpgrc/shared/config.php` (prod overlay) with `.env` ignored in prod
- [ ] Config: Document overlay keys; add redacted “effective-config fingerprint” endpoint; ensure `config:cache` includes overlay
- [ ] UX: Replace RBAC role text inputs with dropdown sourced from `/api/rbac/roles`
- [ ] UX: Add helper text/examples for filters; pre-validate on client
- [ ] Tests: Add RBAC idempotency tests (double attach; detach non-assigned no-op)
- [ ] Tests: Add replace-with-empty and diff assertions for audit `added/removed`
- [ ] Tests: Auth gate with `require_auth=true` (401 unauth, 200 authed; `actor_id` present when authed)
- [ ] Tests: Audit verification of canonical+alias events and `RBAC` casing
- [ ] Docs/OpenAPI: Add `/audit/categories` path and response schema
- [ ] Docs/OpenAPI: Update 422 schemas for `ROLE_NOT_FOUND` and role-name constraints
- [ ] Release: Tag-triggered GHCR image build; attach OpenAPI + web assets

---

### Immediate Next Steps — merged and prioritized

1. **OpenAPI/Swagger polish**
   - [x] Serve YAML at `/api/openapi.yaml` and Swagger UI at `/api/docs`
   - [ ] Serve JSON at `/api/openapi.json`
   - [x] Add a 4XX response to `GET /docs` (present: `404`)
   - [ ] Add `/audit/categories` to spec with schema; document RBAC 422 (`ROLE_NOT_FOUND`) and role-name constraints
   - [ ] Wire Spectral (or Redocly rules) lint into CI

2. **API & Tests**
   - [ ] Feature test: `/api/audit/categories` returns enum list
   - [ ] RBAC happy-path matrix: replace `[]`; attach twice; detach non-assigned
   - [ ] RBAC edge cases: spaces/mixed case; >64 → 422; missing roles → 422
   - [ ] Auth gate tests with `require_auth=true`
   - [ ] Audit tests: canonical+alias pairs; category casing

3. **Web UX**
   - [ ] Admin › Audit: disable Apply during load; inline server errors; keep CSV link synced
   - [ ] Admin › RBAC: replace role text inputs with dropdown from `/api/rbac/roles`

4. **CI**
   - [ ] Protect `main` with required jobs and linear history
   - [ ] Add ParaTest; enable PHPUnit and vitest coverage; upload artifacts
   - [ ] Add PHP 8.2 matrix and optional MySQL job
   - [ ] Add `composer audit`, `npm audit`, Dependabot, and `actionlint`

5. **Config**
   - [ ] Implement `ConfigServiceProvider` for overlay merge (shared → app → `.env`)
   - [ ] Add redacted effective-config fingerprint endpoint
   - [ ] Ensure `config:cache` includes overlay

6. **Release**
   - [ ] Tag `v0.4.6`, build/push GHCR, attach `openapi.yaml` and `web/dist` artifacts