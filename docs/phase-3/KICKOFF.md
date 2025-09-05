# Phase 3 — Module Foundation Kickoff

## Instruction Preamble
- **Date:** 2025-09-05  
- **Phase:** 3  
- **Goal:** Establish scaffolding for module foundation (ModuleManager, manifests, capabilities registry, and stubs for Risks, Compliance, Audits, Policies).  
- **Constraints:**  
  - Docs-first, deterministic mode.  
  - Full-file outputs only with header discriminator.  
  - No functional logic beyond stubs.  
  - Traceability to Charter, Roadmap, and Backlog.  

---

## Scope
- Introduce **ModuleManager** to handle module lifecycle (load, enable/disable, manifest validation).  
- Define canonical **`module.json` schema**.  
- Create **Capabilities registry** for cross-module feature discovery.  
- Provide non-functional stubs for core modules: **Risks, Compliance, Audits, Policies**.  
- Add placeholder `ServiceProvider` classes for each module.  
- Add placeholder OpenAPI fragments for each module.  

---

## Out-of-Scope
- No business logic or persistence yet.  
- No RBAC enforcement (deferred to Phase 4 with CORE-004).  
- No UI wiring beyond navigation stubs.  
- No migrations beyond empty placeholders.  

---

## Deliverables (docs + stubs)

**Docs**
- `/docs/phase-3/KICKOFF.md` (this file)  
- `/docs/modules/module-schema.md` (define manifest schema)  

**Core Module System**
- `/api/app/Services/Modules/ModuleManager.php` (skeleton class)  
- `/api/app/Services/Modules/CapabilitiesRegistry.php` (skeleton class)  
- `/api/app/Contracts/ModuleInterface.php`  

**Schema**
- `/api/module.schema.json` (JSON Schema for `module.json`)  

**Module Stubs**
- `/modules/risks/module.json` (manifest stub)  
- `/modules/risks/RisksServiceProvider.php` (stub)  
- `/modules/compliance/module.json` (manifest stub)  
- `/modules/compliance/ComplianceServiceProvider.php` (stub)  
- `/modules/audits/module.json` (manifest stub)  
- `/modules/audits/AuditsServiceProvider.php` (stub)  
- `/modules/policies/module.json` (manifest stub)  
- `/modules/policies/PoliciesServiceProvider.php` (stub)  

**OpenAPI Placeholders**
- `/modules/risks/openapi.yaml`  
- `/modules/compliance/openapi.yaml`  
- `/modules/audits/openapi.yaml`  
- `/modules/policies/openapi.yaml`  

---

## Acceptance Criteria
- ModuleManager, CapabilitiesRegistry, and Contracts exist as stubs.  
- `module.schema.json` defines manifest format.  
- Each module has `module.json`, ServiceProvider stub, and empty OpenAPI fragment.  
- Routes/UI not yet wired.  
- CI passes with guardrails.  

---

## Risks and Mitigations
- **Scope creep into functional modules** → enforce stub-only policy.  
- **Schema drift** → enforce `module.schema.json` validation in CI in later phases.  
- **Capability mismatches** → centralize in CapabilitiesRegistry.  

---

## References
- Charter.md Section C (Module System):contentReference[oaicite:0]{index=0}  
- ROADMAP.md Phase 3:contentReference[oaicite:1]{index=1}  
- BACKLOG.md items: RISK-001, COMP-001, AUD-001, POL-001:contentReference[oaicite:2]{index=2}  
