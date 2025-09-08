# 🔗 phpGRC Capability Matrix

Capabilities provided/consumed by modules.  
Consumers must degrade gracefully if providers are absent.

| Capability                | Provider(s) | Consumer(s)                 | Status                                                                 |
|--------------------------|-------------|-----------------------------|------------------------------------------------------------------------|
| core.settings.manage     | Core        | All modules                 | Phase 4 stubs implemented (echo+validate)                              |
| core.auth.local          | Core        | All modules                 | Phase 2 scaffold                                                       |
| core.auth.mfa.totp       | Core        | All modules                 | Phase 2 scaffold                                                       |
| core.auth.break_glass    | Core        | Admin Ops                   | Phase 2 scaffold                                                       |
| core.rbac                | Core        | All modules                 | **Phase 4 enforced** (Sanctum PAT + RbacMiddleware; JSON 401/403)      |
| core.avatars             | Core        | All modules (user refs)     | Phase 4 stubs implemented (upload validate)                            |
| core.audit.log           | Core        | All modules                 | **Phase 4 implemented** (DB-backed, retention, strict params, hooks)   |
| core.evidence.manage     | Core        | Risks, Compliance, Audits   | **Phase 4 implemented** (persist + SHA-256 + versioning + listing + HEAD/ETag) |
| core.exports             | Core        | Reporting                   | Phase 4 stubs implemented (job/status stubs)                           |
| risks.read/write         | Risks       | Audits, Compliance, Reports | Phase 3 stubs                                                          |
| risks.scoring            | Risks       | Reporting                   | Phase 3 stubs                                                          |
| risks.treatment          | Risks       | Workflows (future)          | Phase 3 stubs                                                          |
| compliance.controls      | Compliance  | Risks, Audits, Policies     | Phase 3 stubs                                                          |
| compliance.regulations   | Compliance  | Reporting                   | Phase 3 stubs                                                          |
| audits.plan/manage       | Audits      | Risks, Compliance, Reports  | Phase 3 stubs                                                          |
| policies.manage          | Policies    | Compliance, Risks           | Phase 3 stubs                                                          |
| reporting.dashboards     | Reporting   | All modules                 | Phase 5 target                                                         |
| incidents.manage         | Incidents   | Risks, BCP, Reporting       | Phase 6 target                                                         |
| vendors.manage           | Vendors     | Risks, Reporting            | Phase 6 target                                                         |
| cyber.metrics            | Cyber Risk  | Risks, Reporting            | Phase 6 target                                                         |
| bcp.manage               | BCP         | Incidents, Reporting        | Phase 6 target                                                         |
| tasks.assign             | Future: Tasks       | Risks, Audits, Incidents   | Future                                                                 |
| vendors.portal           | Future: VendorPortal| Vendor Risk              | Future                                                                 |
| assets.registry          | Future: Assets     | Risks, Incidents, KRIs     | Future                                                                 |
| indicators.manage        | Future: Indicators | Reporting, Risks, Compliance| Future                                                                 |
| cases.manage             | Future: Cases      | Compliance, HR, Legal      | Future                                                                 |
| integrations.bus         | Future: Bus        | Assets, Indicators, Cyber  | Future                                                                 |
