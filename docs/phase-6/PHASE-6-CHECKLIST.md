# ✅ Phase 6 Detailed Checklist

Trackable tasks for the Phase 6 Integrations program. Keep items unchecked until code, tests, docs, and reviews are complete.

## 0. Foundations
- [x] Confirm Integration Bus contract schema approved and published (`docs/integrations/INTEGRATION-BUS-CONTRACT.md`).
- [x] Update architecture diagrams + SDK snippets for connector developers (`docs/integrations/INTEGRATION-BUS-DEVELOPER-GUIDE.md`).
- [x] Ensure queue infrastructure sized (worker pools, retry policies, dead-letter strategy) (`docs/ops/INTEGRATION-BUS-QUEUE.md`).
- [x] Extend observability pipeline for Bus metrics/log aggregation.
- [x] Refresh ROADMAP.md and CAPABILITIES.md entries when milestones close.

## 1. Integration Bus MVP
- [x] Implement queue jobs and event dispatchers per connector type (`App\Jobs\IntegrationBus\ProcessIntegrationBusMessage`, `App\Services\IntegrationBus\IntegrationBusDispatcher`, events under `App\Events\IntegrationBus\*`).
- [x] Create encrypted configuration storage + CRUD UI/API for connector secrets (`api/app/Models/IntegrationConnector.php`, `/api/integrations/connectors*`).
- [x] Add structured logging + audit events for connector activity.
- [x] Provide connector validation harness (payload schema + provenance headers).
- [x] Ship Bus-focused PHPUnit/Psalm coverage and integration smoke tests.

## 2. External Authentication Expansion
- [x] Build admin UI for IdP provider management (enable/disable, evaluation order). _SPA now supports listing, toggles, ordering, create/delete._
- [x] Implement IdP abstraction with audit logging + health checks. _Drivers encapsulate validation/health, audits logged for create/update/delete/health._
- [x] Deliver OIDC provider support (token validation, JIT provisioning with role templates).
- [x] Add SAML support with metadata import/export.
- [x] Add LDAP support (bind strategy, search filters, TLS requirements).
- [ ] Add Entra ID support leveraging OIDC implementation.
- [ ] Validate MFA interplay (IdP-asserted claims + local TOTP fallback).
- [ ] Update API docs and Admin guides with configuration steps.

## 3. Asset Ingestion
- [ ] Define and migrate normalized `assets` schema with metadata JSON + lineage fields.
- [ ] Build deterministic external ID strategy per connector.
- [ ] Implement Integration Bus ingest handlers for initial CMDB/cloud/IPAM feeds.
- [ ] Add asset dedup/version logic with audit trails.
- [ ] Expose asset list/search APIs + SPA views.
- [ ] Document schema + connector contract in developer docs.

## 4. Indicators Framework
- [ ] Create DB tables for indicator definitions, calculations, thresholds.
- [ ] Seed curated KPI/KRI catalog (Risks, Compliance starting set).
- [ ] Implement scheduled Integration Bus jobs for indicator computation.
- [ ] Surface indicator APIs for reporting + alert hooks.
- [ ] Add SPA visualizations (dashboards/widgets) with capability guards.
- [ ] Write alerting playbooks + update reporting documentation.

## 5. Incident Management
- [ ] Implement incident CRUD with state machine (New → Triage → Contained → Eradicated → Recovered → Closed; Postmortem flag).
- [ ] Wire SLA timers via queued jobs; emit escalation events/audit entries.
- [ ] Integrate with Risks/Evidence via capability-gated links; hide UI when unavailable.
- [ ] Provide incident timeline + attachment handling in SPA.
- [ ] Ship PHPUnit feature tests + Playwright coverage for flows.
- [ ] Document incident workflows and SLA configuration.

## 6. Vendor Inventory
- [ ] Design vendor schema (profile, contacts, services, risk factors).
- [ ] Implement weighted risk scoring engine with admin-tunable weights.
- [ ] Build SPA screens for catalog management and scoring outputs.
- [ ] Integrate optional Integration Bus enrichment adapters (toggleable).
- [ ] Cover APIs with PHPUnit + doc updates, including risk scoring formulas.

## 7. BCP/DRP Workflows
- [ ] Create BCP plan schema (critical processes, RTO/RPO, dependencies).
- [ ] Build plan lifecycle states (Draft/Approved/Under Test/Active/Retired) with audit events.
- [ ] Link incidents via capability checks; ensure graceful degrade.
- [ ] Implement exercise task templates and scheduling reminders.
- [ ] Add SPA management views + reports.
- [ ] Update ops runbooks and continuity documentation.

## 8. Cyber Metrics
- [ ] Define `cyber_metrics` schema keyed by asset + timeframe.
- [ ] Build Integration Bus ingestion for vulnerability scanners.
- [ ] Build Integration Bus ingestion for SIEM summary feeds.
- [ ] Compute residual risk scores; expose APIs for reporting.
- [ ] Add SPA dashboards/visualizations referencing residual risk.
- [ ] Document ingestion requirements and testing strategy.

## 9. Testing & Compliance
- [ ] Ensure PHPUnit, PHPStan L9, Psalm, Pint, and frontend test suites cover new code paths.
- [ ] Add integration tests for queue workers, connectors, and IdP flows.
- [ ] Update security review checklist with external auth + connector hardening.
- [ ] Perform accessibility review for new SPA sections (Incidents, Vendors, BCP, Metrics).

## 10. Release Readiness
- [ ] Update CHANGELOG/RELEASE notes with Phase 6 features and breaking changes.
- [ ] Validate migration scripts + rollback plans in staging.
- [ ] Conduct end-to-end smoke (auth, connectors, module workflows) before release.
- [ ] Confirm documentation packages (Admin/User docs, API references) are ready for publication.
