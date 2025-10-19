# 🔗 phpGRC Capability Matrix

Capabilities provided/consumed by modules.  
Consumers must degrade gracefully if providers are absent.

| Capability                | Provider(s) | Consumer(s)                 | Status                                                                                          |
|--------------------------|-------------|-----------------------------|-------------------------------------------------------------------------------------------------|
| core.settings.manage     | Core        | All modules                 | **Phase 4 implemented** (echo+validate; persisted; audited)                                    |
| core.auth.local          | Core        | All modules                 | Phase 2 scaffold                                                                                |
| core.auth.mfa.totp       | Core        | All modules                 | Phase 2 scaffold                                                                                |
| core.auth.break_glass    | Core        | Admin Ops                   | Phase 2 scaffold                                                                                |
| core.rbac                | Core        | All modules                 | **Phase 4 enforced** (Sanctum PAT + RbacMiddleware; JSON 401/403; human-readable IDs)          |
| core.avatars             | Core        | All modules (user refs)     | **Phase 4 implemented** (upload validate; normalize to WEBP 128px)                             |
| core.audit.log           | Core        | All modules                 | **Phase 4 implemented** (DB-backed, retention job, strict params, listing filters, RBAC events)|
| core.evidence.manage     | Core        | Risks, Compliance, Audits   | **Phase 4 implemented** (persist + SHA-256 + versioning + listing + HEAD/ETag)                 |
| core.exports             | Core        | Reporting                   | **Phase 4 implemented** (job/status/download; CSV/JSON/PDF; gated by capability)               |
| core.theme.view          | Core        | Web SPA, Admin              | **Phase 5.5 implemented** (read-only theming + branding endpoints; roles: `role_admin`, `role_theme_manager`, `role_theme_auditor`) |
| core.theme.manage        | Core        | Web SPA, Admin              | **Phase 5.5 implemented** (UI settings designer + branding writes; roles: `role_admin`, `role_theme_manager`) |
| core.theme.pack.manage   | Core        | Admin                       | **Phase 5.5 implemented** (theme pack import/update/delete; roles: `role_admin`, `role_theme_manager`) |
| ui.prefs                 | Core        | Web SPA                     | **Phase 5.5 implemented** (user theme/mode/sidebar; gated by `core.theme.view`, read includes `role_theme_auditor`) |
| reporting.dashboards     | Reporting   | All modules                 | Phase 5 target                                                                                  |
| incidents.manage         | Incidents   | Risks, BCP, Reporting       | Phase 6 target                                                                                  |
| vendors.manage           | Vendors     | Risks, Reporting            | Phase 6 target                                                                                  |
| cyber.metrics            | Cyber Risk  | Risks, Reporting            | Phase 6 target                                                                                  |
| bcp.manage               | BCP         | Incidents, Reporting        | Phase 6 target                                                                                  |
| tasks.assign             | Future: Tasks       | Risks, Audits, Incidents   | Future                                                                                          |
| vendors.portal           | Future: VendorPortal| Vendor Risk              | Future                                                                                          |
| assets.registry          | Future: Assets     | Risks, Incidents, KRIs     | Future                                                                                          |
| indicators.manage        | Future: Indicators | Reporting, Risks, Compliance| Future                                                                                          |
| cases.manage             | Future: Cases      | Compliance, HR, Legal      | Future                                                                                          |
| integrations.bus         | Integration Bus    | Assets, Indicators, Cyber  | Phase 6 in progress — queue workers emit observability audits + connector telemetry; handlers next |
| integrations.connectors  | Integration Bus    | Admin, Integrations        | **Phase 6 implemented** (encrypted secrets storage + CRUD, policy `integrations.connectors.manage`) |
