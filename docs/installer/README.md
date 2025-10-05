# phpGRC Installer & Setup Wizard — Planning Readme

**File:** `@phpgrc:/docs/installer/README.md`  
**Title:** phpGRC Installer & Setup Wizard — Planning Readme  
**Purpose:** Orient contributors to CORE-001 Bootstrap Installer & first-run Setup Wizard planning deliverables. Summarizes scope, constraints, file map, and review gates for Phase 1 (planning + stubs scaffold only).

---

## Scope
- **Phase:** 1  
- **Description:**
  - Produce planning artifacts and non-functional stubs only.
  - Only DB connection configuration is stored on disk at `/opt/phpgrc/shared/config.php`.
  - All other settings persist in the DB (system of record).
  - Redirect-to-setup behavior and all functional code are deferred to later implementation phases per ROADMAP.

---

## Acceptance Criteria
- Instruction Preamble drafted and aligned with Charter/Backlog/Playbook  
- Wizard step list, API contracts, payload schemas, error taxonomy, and atomic-write protocol specified  
- Minimal file stubs listed with paths and purposes (no logic yet)  
- Security, idempotency, and resume semantics defined for future code  

---

## Non-Goals
- No working controllers, services, or UI logic  
- No DB migrations beyond listing their planned filenames/locations  
- No SPA routing beyond route naming and sequencing  
- No systemd/services or installer automation beyond doc/spec  

---

## File Map

### Docs
- `/docs/installer/CORE-001.md` — canonical planning spec (source of truth)  
- `/docs/installer/README.md` — this overview file  

### Stubs

**Scripts**
- `/scripts/install/bootstrap.sh`

**API Controllers**
- `/api/app/Http/Controllers/Setup/SetupStatusController.php`
- `/api/app/Http/Controllers/Setup/DbController.php`
- `/api/app/Http/Controllers/Setup/AppKeyController.php`
- `/api/app/Http/Controllers/Setup/SchemaController.php`
- `/api/app/Http/Controllers/Setup/AdminController.php`
- `/api/app/Http/Controllers/Setup/AdminMfaController.php`
- `/api/app/Http/Controllers/Setup/SmtpController.php`
- `/api/app/Http/Controllers/Setup/IdpController.php`
- `/api/app/Http/Controllers/Setup/FinishController.php`

**Services**
- `/api/app/Services/Setup/ConfigFileWriter.php`

**Models**
- `/api/app/Models/Setting.php`

**Middleware**
- `/api/app/Http/Middleware/SetupGuard.php`

**Routes**
- `/api/routes/api.php`

**Migrations**
- `/api/database/migrations/...create_core_settings_table.php`  
- `/api/database/migrations/...create_users_and_auth_tables.php`

**Web Routes**
- `/web/src/routes/setup/Db.tsx`  
- `/web/src/routes/setup/AppKey.tsx`  
- `/web/src/routes/setup/Schema.tsx`  
- `/web/src/routes/setup/Admin.tsx`  
- `/web/src/routes/setup/AdminMfa.tsx`  
- `/web/src/routes/setup/Smtp.tsx`  
- `/web/src/routes/setup/Idp.tsx`  
- `/web/src/routes/setup/Branding.tsx`  
- `/web/src/routes/setup/Finish.tsx`  
- `/web/src/routes/setup/index.ts`

---

## Review and Guardrails
- **Charter v1.1** — DB-as-system-of-record; stateless app; modularity  
- **BACKLOG** — CORE-001 acceptance criteria  
- **Playbook** — instruction preamble, full-file outputs only, deterministic mode  
- **Note:** CI/guardrails exist but should skip gracefully while `/api` and `/web` are empty.  

---

## Roles and Decision Process
- **Maintainer Approval:** required  
- **Scope Change:** requires ADR in `/docs/DECISIONS`  

---

## Next Steps
- Generate non-functional files with headers and TODO blocks (done)  
- Wire `/api/routes/api.php` placeholders to reserved paths (deferred)  
- Defer functional wiring, DB I/O, and SPA navigation until Phase 2  
