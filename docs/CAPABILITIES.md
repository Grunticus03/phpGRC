# 🔗 phpGRC Capability Matrix

Capabilities provided/consumed by modules.  
Consumers must degrade gracefully if providers are absent.

| Capability                | Provider(s) | Consumer(s)                 | Status |
|--------------------------|-------------|-----------------------------|--------|
| core.settings.manage     | Core        | All modules                 |      |
| core.auth.local          | Core        | All modules                 |      |
| core.auth.mfa.totp       | Core        | All modules                 |      |
| core.auth.break_glass    | Core        | Admin Ops                   |      |
| core.rbac                | Core        | All modules                 |      |
| core.avatars             | Core        | All modules (user refs)     |      |
| core.audit.log           | Core        | All modules                 | ⏳ Phase 4 (baseline in place: `audit_events`) |
| core.evidence.manage     | Core        | Risks, Compliance, Audits   | ⏳ Phase 4 |
| core.exports             | Core        | Reporting                   | ⏳ Phase 4 (stubs in Phase 2 roadmap) |
| risks.read/write         | Risks       | Audits, Compliance, Reports | ⏳ Phase 3 |
| risks.scoring            | Risks       | Reporting                   | ⏳ Phase 3 |
| risks.treatment          | Risks       | Workflows (future)          | ⏳ Phase 3 |
| compliance.controls      | Compliance  | Risks, Audits, Policies     | ⏳ Phase 3 |
| compliance.regulations   | Compliance  | Reporting                   | ⏳ Phase 3 |
| audits.plan/manage       | Audits      | Risks, Compliance, Reports  | ⏳ Phase 3 |
| policies.manage          | Policies    | Compliance, Risks           | ⏳ Phase 3 |
| reporting.dashboards     | Reporting   | All modules                 | ⏳ Phase 5 |
| incidents.manage         | Incidents   | Risks, BCP, Reporting       | ⏳ Phase 6 |
| vendors.manage           | Vendors     | Risks, Reporting            | ⏳ Phase 6 |
| cyber.metrics            | Cyber Risk  | Risks, Reporting            | ⏳ Phase 6 |
| bcp.manage               | BCP         | Incidents, Reporting        | ⏳ Phase 6 |
| tasks.assign             | Future: Tasks       | Risks, Audits, Incidents   | ⏳ Future |
| vendors.portal           | Future: VendorPortal| Vendor Risk              | ⏳ Future |
| assets.registry          | Future: Assets     | Risks, Incidents, KRIs     | ⏳ Future |
| indicators.manage        | Future: Indicators | Reporting, Risks, Compliance| ⏳ Future |
| cases.manage             | Future: Cases      | Compliance, HR, Legal      | ⏳ Future |
| integrations.bus         | Future: Bus        | Assets, Indicators, Cyber  | ⏳ Future |
