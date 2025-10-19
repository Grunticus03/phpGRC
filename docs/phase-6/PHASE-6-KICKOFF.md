# 🚀 Phase 6 Kickoff — Integrations

Phase 6 unlocks external connectivity and continuity workflows across the phpGRC platform. This kickoff doc captures agreed objectives, scope, and decision logs to guide delivery.

## 🎯 Objectives
- Ship Integration Bus MVP so connectors can exchange data safely through Laravel queues and event pipelines.
- Expand authentication options with admin-controlled external IdPs while preserving local/TOTP support.
- Deliver new operational modules (Incidents, Vendors, BCP/DRP, Cyber Metrics, Indicators) that integrate cleanly with existing capabilities.
- Maintain graceful degradation guarantees and keep CI/static analysis guardrails green.

## 📦 Scope & Deliverables
- **Integration Bus MVP**: queue-backed connectors, encrypted config store, structured logging/metrics surface.
- **External Auth Providers**: OIDC → SAML → LDAP → Entra rollout; Web UI knobs for provider enablement and evaluation order; audit logging + JIT provisioning with role templates.
- **Asset Ingestion**: normalized asset schema with extensible metadata, deterministic external IDs, lineage tracking, Integration Bus contract for all feeds.
- **Indicators Framework**: curated KPI/KRI catalog in DB, scheduled calculations via Integration Bus, reporting APIs with alert hooks.
- **Incident Management**: state machine lifecycle with SLA timers, capability-gated links to Risks/Evidence, audit coverage.
- **Vendor Inventory**: vendor catalog, weighted risk scoring engine, optional Integration Bus enrichments.
- **BCP/DRP Workflows**: BIA fields (critical processes, RTO/RPO, dependencies), lifecycle states, incident attachments, exercise templates.
- **Cyber Metrics**: ingest vulnerability scanner + SIEM summaries, normalize to `cyber_metrics`, residual risk calculations.
- **Out of Scope**: sandboxed theme-pack JS/HTML enablement deferred beyond Phase 6.

## 🔭 Future Work & Deferred Items
- **Sandboxed Theme Packs**: revisit dedicated origin/CSP approach post-Phase 6 once integrations stabilize.
- **Endpoint/EDR Telemetry**: extend Cyber Metrics ingest to endpoint detection platforms in later release.
- **Full Workflow Automation**: align BCP incident exercises with future Tasks module once available.
- **Expanded Indicator Catalog**: add KRIs/KCIs for additional modules after initial KPI/KRI rollout.
- **Connector Marketplace**: evaluate packaging/distribution model for third-party Integration Bus connectors in ≥Phase 7.

## 🛡 Security & Operations Review Focus
- Integration Bus: verify per-connector worker pools, retry/dead-letter policies, encrypted secret storage with rotation, RBAC on connector CRUD, structured logging retention, alerting on job failures, back-pressure safeguards, and updated DR runbooks.
- External Auth: validate IdP metadata signatures and claim mappings, confirm MFA fallback behaviour, enforce lockout/brute-force guardrails, audit IdP configuration changes, and document response plans for IdP outages.
- New Modules: classify data for assets/incidents/vendors, confirm capability gates cover every API/UI surface, and ensure evidence linkage inherits existing handling rules.
- Cross-cutting: refresh threat model, extend monitoring dashboards to new queues/endpoints, schedule post-implementation security testing for auth flows.

## 🧪 QA & Test Planning Notes
- Coverage matrix: map checklist tasks to PHPUnit, Psalm/PHPStan expectations, Playwright, and queue integration tests.
- Test fixtures: prepare reusable datasets for connectors (payload schema), IdP claims, incident lifecycle scenarios, and BCP exercises.
- Regression checkpoints: define manual QA passes for auth failover, Integration Bus throughput under load, SPA accessibility, and role-based access behaviours.
- Documentation: store plans/fixtures alongside phase docs to keep QA efforts versioned with code.

## 🔌 Connector Strategy Guidelines
- Design connector archetypes (REST, webhook, file-drop, database) that all translate into the Integration Bus payload schema via shared adapters.
- Keep vendor-specific details in configuration (endpoints, auth, field mappings) and normalize data through transform stages before landing in internal tables.
- Publish a connector manifest describing rate limits, scopes, pagination, and provenance headers to standardize contributions.
- Provide extension hooks + contract validation so third parties can ship connectors without altering core while still meeting quality gates.

## ✅ Key Decisions
| Area | Decision |
| --- | --- |
| Integration Bus | Run on Laravel queues/events with connector-specific worker pools; configs stored encrypted; emit structured logs/metrics. |
| External Auth | Implement IdP abstraction; rollout order OIDC → SAML → LDAP → Entra; admins control provider toggles + evaluation order via Web UI; retain local/TOTP fallback. |
| Asset Ingestion | Use normalized asset schema, deterministic external IDs, Integration Bus payload contract, consolidated assets table with lineage metadata. |
| Indicators | Store curated definitions in DB, compute via scheduled jobs, expose via reporting APIs and alert hooks; initial focus on Risks/Compliance KPI/KRI. |
| Incident Lifecycle | Adopt state machine (`New → Triage → Contained → Eradicated → Recovered → Closed`, optional Postmortem), SLA timers via queued jobs, capability-gated integrations. |
| Vendor Inventory | Provide catalog + weighted risk scoring; optional Integration Bus enrichments without hard dependencies. |
| BCP/DRP | Track BIA fields, lifecycle states, incident attachments, exercise templates; defer full workflow automation. |
| Cyber Metrics | Ingest vuln scanner + SIEM summaries first; normalize to `cyber_metrics`; defer endpoint telemetry. |
| Theme-Pack JS | Defer sandboxed JS/HTML enablement to post-Phase 6 release. |

## 📘 Decision Log

| Date | Item | Owners/Sign-off | Notes |
| --- | --- | --- | --- |
| 2026-01-12 | Integration Bus contract v1.0 approved | Architecture (you), Security (Codex) | Contract documented in `docs/integrations/INTEGRATION-BUS-CONTRACT.md`; JSON Schema published alongside. Checklist item 0.1 ready to close. |

## 🔗 Dependencies & Interfaces
- Relies on existing Core modules: RBAC (CORE-004), Audit (CORE-006), Evidence (CORE-007), Settings APIs.
- Integration Bus contract underpins Asset, Cyber, Indicator workloads; connectors must honor queue payload schema and provenance headers.
- Canonical contract draft tracked at `docs/integrations/INTEGRATION-BUS-CONTRACT.md` (`integration-bus-envelope.schema.json`). Developer guide + diagrams live at `docs/integrations/INTEGRATION-BUS-DEVELOPER-GUIDE.md`.
- Web UI updates required for IdP management, new module navigation, and reporting surfaces.
- Shared audit/logging infrastructure must ingest Bus metrics and IdP events.

## 📅 Milestones (High-Level)
1. **Architecture Readiness**: finalize Integration Bus schema, security review, ops runbooks.
2. **Auth Expansion**: OIDC GA with admin UI; follow-up releases add SAML, LDAP, Entra.
3. **Data Foundations**: asset ingestion pipelines + cyber metrics tables live.
4. **Operational Modules**: incidents, vendors, BCP/DRP shipped with UI + API coverage.
5. **Indicators Launch**: KPI/KRI catalog publishing and alerting.

## 📓 Risks & Mitigations
- **Connector Complexity**: start with limited connector set; publish SDK examples; enforce contract validation.
- **IdP Configuration Errors**: provide test mode + health checks; audit all changes.
- **Data Volume Growth**: benchmark queue throughput; monitor job latency via observability hooks.
- **Module Coupling**: maintain capability guards; design fallbacks when dependencies missing.

## 👥 Roles & Communication
- **Phase Owner**: Product + Architecture leads.
- **Track Leads**: Integration Bus, Authentication, Data Ingestion, Operational Modules, Reporting.
- **Cadence**: weekly Phase 6 standup; async decision log updates in this doc; milestone demos.
- **Documentation**: updates to ROADMAP.md, CAPABILITIES.md, module manifests, and API docs as features land.
