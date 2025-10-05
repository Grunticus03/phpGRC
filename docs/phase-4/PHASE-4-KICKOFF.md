# Phase 4 — Core App Usable Kickoff

## Instruction Preamble
- **Date:** 2025-09-05
- **Phase:** 4
- **Goal:** Establish scaffolds for core features that make phpGRC minimally usable (settings, RBAC, audit, evidence, exports, avatars).
- **Constraints:**
  - Docs-first. No functional persistence beyond placeholders.
  - Full-file outputs only with header discriminator.
  - Traceability to Charter, Roadmap, and Backlog.
  - Deterministic mode.

---

## Scope
Implement non-functional scaffolds for:
- **Settings UI** — framework for configs (echo-only; persistence deferred).
- **RBAC Roles** — Admin, Auditor, Risk Manager, User (policy/middleware stubs only).
- **Audit Trail** — placeholder DB migration and controller.
- **Evidence Pipeline** — migration stubs + API controller placeholders.
- **Exports** — extend Phase 2 stubs; add placeholder job/status pattern.
- **Avatars** — controller stub, file upload route, config placeholders.

---

## Out-of-Scope
- Real auth enforcement or RBAC decisions.
- Real evidence storage or hashing.
- Background jobs or file export persistence.
- Avatar image processing.
- Dashboards or reports.

---

## Deliverables (docs + stubs)

**Docs**
- `/docs/phase-4/KICKOFF.md` (this file)
- `/docs/core/PHASE-4-SPEC.md` (detailed contracts)

**API (stubs only)**
- `/api/app/Http/Controllers/Admin/SettingsController.php`
- `/api/app/Http/Controllers/Rbac/RolesController.php`
- `/api/app/Http/Controllers/Audit/AuditController.php`
- `/api/app/Http/Controllers/Evidence/EvidenceController.php`
- `/api/app/Http/Controllers/Export/ExportController.php` (extend)
- `/api/app/Http/Controllers/Avatar/AvatarController.php`
- `/api/app/Models/Role.php`
- `/api/app/Models/AuditEvent.php`
- `/api/app/Models/Evidence.php`
- `/api/app/Models/Avatar.php`
- `/api/app/Http/Middleware/RbacMiddleware.php`
- `/api/routes/api.php` (extend with new routes)

**Migrations (placeholders)**
- `/api/database/migrations/0000_00_00_000100_create_roles_table.php`
- `/api/database/migrations/0000_00_00_000110_create_audit_events_table.php`
- `/api/database/migrations/0000_00_00_000120_create_evidence_table.php`
- `/api/database/migrations/0000_00_00_000130_create_avatars_table.php`

**Web (stubs only)**
- `/web/src/routes/admin/Settings.tsx`
- `/web/src/routes/admin/Roles.tsx`
- `/web/src/routes/audit/index.tsx`
- `/web/src/routes/evidence/index.tsx`
- `/web/src/routes/exports/index.tsx`
- `/web/src/routes/profile/Avatar.tsx`

**Config placeholders**
- `core.rbac.*` (roles scaffold)
- `core.audit.*` (retention default)
- `core.evidence.*` (max file size default)
- `core.avatars.*` (size, format defaults)

---

## API Endpoints (placeholders)
- `GET /api/admin/settings` → echo config keys.
- `POST /api/admin/settings` → no-op update.
- `GET /api/rbac/roles` → list scaffold roles.
- `POST /api/rbac/roles` → no-op create.
- `GET /api/audit` → empty list placeholder.
- `POST /api/evidence` → no-op upload.
- `GET /api/exports/:id/status` → always pending.
- `POST /api/exports/:type` → returns fake job id.
- `POST /api/avatar` → no-op upload.

---

## Error Taxonomy (incremental)
RBAC_DISABLED, ROLE_NOT_FOUND, AUDIT_NOT_ENABLED,
EVIDENCE_TOO_LARGE, EVIDENCE_NOT_ENABLED,
EXPORT_NOT_READY, AVATAR_INVALID, AVATAR_TOO_LARGE,
UNAUTHORIZED, UNAUTHENTICATED, INTERNAL_ERROR

---

## Sequencing
1) Add config keys (core.*).
2) Add API controllers and routes stubs.
3) Add models and migration placeholders.
4) Add web route stubs.
5) Keep CI guardrails green.

---

## Acceptance Criteria
- Files above exist with headers and TODOs.
- Routes compile in skeleton form.
- Config keys present with defaults.
- No functional behavior.
- CI passes on stubs.

---

## References
- Charter v1.1; Roadmap Phase 4.
- Backlog: CORE-003, CORE-004, CORE-006, CORE-007, CORE-008, CORE-010.
