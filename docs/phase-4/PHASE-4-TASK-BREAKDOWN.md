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
- Last updated: 2025-09-16

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

//// Phase 4 – Task Breakdown (updated)
## Session/Quality Gates
- [x] Psalm/PHPStan/Pint clean
- [x] PHPUnit green in CI
- [x] Contracts frozen in `PHASE-4-SPEC.md` for delivered areas
- [x] CI: Upload artifacts (`api/junit.xml`, `api/storage/logs/*.log`, `web/dist`)
- [x] UX: Replace free-text Audit category with dropdown enum; normalize case
- [x] UX: Surface 422 field-level errors inline on Settings forms
- [ ] UX pass on admin screens (Phase 5)
- [x] CI: Add coverage (`--coverage-clover coverage.xml`) and upload/report; include vitest coverage/artifacts
- [x] CI: Add `composer audit` and `npm audit --audit-level=high`
- [x] CI: Add Dependabot (Composer, npm, GitHub Actions)
- [x] CI: Add `actionlint` step to lint workflow YAML
- [x] QA: Raise PHPStan level one notch; fix violations or baseline deltas
- [x] QA: Raise Psalm level/config; keep threads; stabilize baseline
- [x] Config: Implement early boot merge of `/opt/phpgrc/shared/config.php` (prod overlay) with `.env` ignored in prod
- [x] Config: Document overlay keys; add redacted “effective-config fingerprint” endpoint; ensure `config:cache` includes overlay
- [x] UX: Replace RBAC role text inputs with dropdown sourced from `/api/rbac/roles`
- [ ] UX: Add helper text/examples for filters; pre-validate on client
- [x] Tests: Add RBAC idempotency tests (double attach; detach non-assigned no-op)
- [x] Tests: Add replace-with-empty and diff assertions for audit `added/removed`
- [x] Tests: Auth gate with `require_auth=true` (401 unauth, 200 authed; `actor_id` present when authed)
- [x] Tests: Audit verification of canonical+alias events and `RBAC` casing
- [x] Docs/OpenAPI: Add `/audit/categories` path and response schema
- [x] Docs/OpenAPI: Update 422 schemas for `ROLE_NOT_FOUND` and role-name constraints
- [x] Docs/OpenAPI: Enforce non-empty-string for `audit.action` and `audit.entity_id`

——— Web Build/Type Infra ———
- [x] Web: Add runtime deps `react`, `react-dom`, `react-router-dom`
- [x] Web: Resolve Node ESM warning by adding `"type": "module"` to `web/package.json`
- [x] Web: Align TS config for Vite/React (`jsx: react-jsx`, `moduleResolution: bundler`, ESM interop)
- [x] Web: `npm run typecheck` clean locally and in CI
- [x] Web: ESLint clean without suppressions

——— Web: Admin UI ———
- [x] Admin → Roles screen (list + create), with tests
- [x] Admin → User Roles screen (lookup + attach/detach), with tests
- [x] User Roles: Replace-all roles multi-select flow, with tests
- [x] Router/Nav wiring for Admin routes

——— Deploy ———
- [x] GitHub Actions manual deploy via SSH/rsync with SPA build upload
- [x] Shared storage perms hardened (setgid dirs; only chmod entries owned by SSH user)
- [x] Remote env export fixed for `set -u` (`RELEASE_DIR` unbound variable)
- [x] Atomic current symlink switch and health check
- [x] Manual deployment verified to dev

### Newly completed this session
- [x] Audit: Custom builder `AuditEventBuilder` to JSON-encode `meta` on bulk inserts
- [x] Audit: Model wired to custom builder with #[Override]; static analysis clean
- [x] Audit: Streamed CSV export via `CsvStreamResponse` with exact `text/csv`
- [x] Audit: Export controller uses streamed response; headers fixed; chunking acceptable via `get()`
- [x] Tests: `AuditApiTest` filters green; `AuditCsvExportTest` headers/body assertions green

### Immediate Next Steps — merged and prioritized
1. **Audit retention**: tests for purge job and scheduler (UTC 03:10 default), honoring `core.audit.retention_days`.
2. **OpenAPI**: confirm examples for `/audit` and `/audit/export.csv` match emitted shapes, including header note for `text/csv`.
3. **Perf**: switch CSV iteration to `$q->cursor()` with memory cap test; ensure identical output.
4. **Docs**: add developer note on `Builder::insert` bypassing casts and rationale for custom builder.
