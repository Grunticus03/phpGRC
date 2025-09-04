\# phpGRC — Canonical Charter v1.1 (Authoritative Baseline)



---



\## A) VISION \& SCOPE

\*\*Goal:\*\* Production-grade Governance, Risk \& Compliance (GRC) web app with first-class auditability, evidence handling, and modular architecture.  



\*\*Principles:\*\*

\- \*\*Everything in DB:\*\* All data, settings, evidence, audit logs, avatars, exports stored in DB (system of record). Only DB connection config lives outside.  

\- \*\*Stateless app:\*\* Multiple frontends can attach to a single DB.  

\- \*\*Fully modular:\*\* Core app usable standalone; new modules are drop-in, auto-registered, and conditional features appear only if required modules are present.  



\*\*Non-goals (v1):\*\*

\- Multitenancy.  

\- Public self-signup.  

\- Custom ad-hoc report builder beyond defined exports.  



---



\## B) ARCHITECTURE (AUTHORITATIVE)

\- \*\*Monorepo structure:\*\*

---/phpGRC

---/api ← Laravel 11 JSON API

---/web ← React SPA (Vite)

---/docs ← Charter, ROADMAP, BACKLOG.yml, CAPABILITIES.md, RFCs

---/.github ← Workflows (ci.yml, deploy.yml)

---/scripts ← Deployment helpers



\- \*\*Stack:\*\*  

\- OS: Ubuntu 24.04 LTS  

\- Web: Apache + PHP-FPM  

\- PHP: 8.3.x (≥8.3.16)  

\- DB: MySQL 8 (InnoDB)  

\- Backend: Laravel 11 (API only; no Blade)  

\- Frontend: React SPA (Vite)  

\- Charts: Chart.js (via react-chartjs-2)  

\- UI: Bootstrap 5.x + Bootswatch themes  

\- Cache/Queue (optional): Redis  



\- \*\*Auth (phased):\*\*  

\- v1: Local + Sanctum SPA mode.  

\- Future: OIDC, SAML, LDAP, Entra.  

\- MFA: Locally managed/hosted TOTP.  



\- \*\*PDF engine:\*\* Dompdf (v1 default).  

\- \*\*Swagger/OpenAPI:\*\* `/api/openapi.json` + `/api/docs`.  



---



\## C) MODULE SYSTEM

\- \*\*Module manifest (`module.json`)\*\*:  

\- Fields: `name`, `version`, `requires\[]`, `capabilities\[]`, `migrations`, `settings`, `ui.nav`.  

\- \*\*ModuleManager:\*\* loads manifests, registers ServiceProviders, mounts routes/UI only if dependencies satisfied.  

\- \*\*Capabilities Registry:\*\* core service to check which capabilities are provided; consumers query registry to enable/disable features dynamically.  

\- \*\*Cross-module:\*\* events \& contracts only; no direct imports.  

\- \*\*Lifecycle:\*\*  

\- Hot enable/disable.  

\- Graceful degrade if capability absent.  

\- Namespaced migrations/settings/audit.  

\- Versioning via semver.  



\*\*Core = framework + auth + RBAC + settings UI + audit trail + evidence pipeline + exports + Swagger.\*\*



---



\## D) RBAC MODEL

\- \*\*Roles:\*\* Admin, Auditor, Risk Manager, User.  

\- \*\*Permissions:\*\* Policy-based, module-scoped (`<module>.<action>`).  

\- \*\*Enforcement:\*\* Laravel Policies + Middleware.  

\- \*\*Emergency access:\*\* Break-glass login (DB-flag enabled only, MFA required, rate-limited, full audit).  



---



\## E) SECURITY BASELINES

\- Password hashing: Argon2id.  

\- MFA: TOTP.  

\- Session fixation defense, CSRF tokens, XSS escape, CSP headers.  

\- Login throttling, lockouts, rate limiting.  

\- Optional LDAP/SAML/OIDC/Entra (≥Phase 6).  

\- Audit all config/auth changes.  



---



\## F) ROADMAP PHASES



\### \*\*Phase 0 — Docs-first foundation\*\*

\- Charter v1.1 committed.  

\- `docs/ROADMAP.md` (phase/step breakdown).  

\- `docs/BACKLOG.yml` seeded with modules/features.  

\- `docs/CAPABILITIES.md` initial matrix.  

\- `docs/rfcs/000-template.md` (RFC template).  



\### \*\*Phase 1 — Guardrails + Setup Wizard scaffold\*\*

\- Monorepo structure created (`/api`, `/web`, `/docs`).  

\- Max CI/CD guardrails established.  

\- Installer/first-run setup wizard scaffold (planning + stub).  



\### \*\*Phase 2 — Auth/Routing\*\*

\- Local auth + Sanctum SPA mode.  

\- MFA TOTP.  

\- Break-glass login (DB-flag).  

\- Admin Settings UI framework (DB-backed).  



\### \*\*Phase 3 — Module foundation\*\*

\- ModuleManager + manifests.  

\- Capability registry + conditional routes/UI.  

\- Stubs for core modules: Risks, Compliance, Audit, Policies.  



\### \*\*Phase 4 — Core app usable\*\*

\- Settings UI (all configs, RBAC).  

\- Audit Trail.  

\- Evidence pipeline (DB storage, SHA-256, versioning).  

\- Exports: CSV, JSON, PDF.  

\- Avatars: 128px WEBP, upload->crop/resize, fallback initials.  



\### \*\*Phase 5 — Swagger polish + dashboards\*\*

\- Swagger/OpenAPI validated + Spectral lint.  

\- Predefined reports \& dashboards.  

\- Admin docs \& User docs baseline.  



\### \*\*Phase 6 — Integrations\*\*

\- Integration Bus MVP (connectors, pipelines).  

\- External Auth providers: OIDC, SAML, LDAP, Entra.  

\- Asset ingestion (CMDB, cloud, IPAM).  

\- Indicator framework.  



\### \*\*Phase 7 — Release v1.0\*\*

\- Prod deploy workflows.  

\- Full Admin/User docs.  

\- Hardening (CSP tuning, IDS/IPS, backup UI).  

\- Release tag `v1.0.0`.  



---



\## G) CI/CD GUARDRAILS

\- \*\*Blocking:\*\*  

\- PSR-12 (PHPCS).  

\- PHPStan level 5.  

\- Psalm “no-new-issues”.  

\- PHPUnit (feature + unit).  

\- Enlightn fail on high/critical.  

\- composer-audit fail on high/critical (warn on medium).  

\- OpenAPI lint + breaking-change diff (Spectral).  

\- commitlint (Conventional Commits).  

\- CODEOWNERS review.  

\- \*\*Efficient:\*\* single CI + single Deploy workflow. Path filters \& caches.  



---



\## H) DEPLOYMENT

\- GitHub Actions → rsync to test/prod servers.  

\- Test: `deploy.phpgrc.gruntlabs.net`.  

\- Prod: `phpgrc.gruntlabs.net`.  

\- Domain-agnostic workflows (others can deploy elsewhere).  

\- Release pattern: `releases/` + `current` symlink.  

\- DB = single source of truth (app stateless).  



---



\## I) EVIDENCE PIPELINE

\- Stored in DB with SHA-256.  

\- Versioning enforced.  

\- Attestation log.  

\- Size limit: \*\*25 MB default\*\* (configurable via Admin UI).  

\- Temp local storage allowed pre-scan only.  



---



\## J) AUDIT TRAIL

\- Every action logged (actor, time, context) in DB.  

\- Configurable retention via Admin UI; max 2 years.  

\- Emergency login audited (who/when/why/IP/UA).  



---



\## K) EXPORTS

\- CSV, JSON, PDF.  

\- Predefined reports only in v1.  

\- Export artifacts stored in DB.  

\- Export jobs follow job/status pattern.  



---



\## L) UX RULES

\- Bootswatch theme selectable.  

\- Sticky footer, responsive grid.  

\- Accessibility: WCAG 2.1 AA baseline.  

\- Avatars: 128px WEBP canonical; user upload→crop/resize; fallback initials.  



---



\## M) INSTALLER / SETUP WIZARD

\- Downloadable bootstrap script or `git clone`.  

\- First-run Setup Webpage:  

\- DB config.  

\- App key \& schema init.  

\- Admin account (local auth + MFA).  

\- External IdP option.  

\- SMTP + notifications setup.  

\- Evidence defaults (25 MB).  

\- Branding/theme.  

\- Redirect to setup until complete.  

\- Stateless principle preserved: only DB config file stored outside DB.  



---



\## N) PLAYBOOK

\- \*\*Instruction Preamble required\*\* before file outputs.  

\- \*\*Always request files\*\*; \*\*full-file outputs only\*\* with header discriminator.  

\- \*\*No scope creep\*\* outside Charter/Backlog.  

\- No direct commits to main; PRs only with green checks.  



---



\## O) ACCEPTANCE CRITERIA

\- All guardrails green.  

\- Phase complete = all steps merged \& tagged.  

\- Tags: `v0.x.y-phaseN-stepM`.  

\- Installer/setup wizard completes without manual file edits.  

\- Admin UI exposes all configs (except backdoor DB flag).  

\- Break-glass login DB-flag only, time-bound, MFA-gated, audited.  



---



\## P) ANTI-CREEP

\- No unapproved scope expansion.  

\- Bugfixes allowed.  

\- Features require Charter amendment.  



---



\## Q) LICENSE

\- MIT + Common Clause.  

\- Restrictions: no commercial/for-profit use; no significant alterations of vision/image.  



---



\## R) FUTURE MODULES (not in v1, planned for ≥v1.1)

1\. \*\*Task \& Workflow Management\*\*  

2\. \*\*Third-Party Engagement Portal\*\*  

3\. \*\*Asset \& Configuration Management\*\* (with external sources via Integration Bus)  

4\. \*\*Indicators (KPI/KRI/KCI)\*\*  

5\. \*\*Case Management (Whistleblower/Ethics)\*\*  

6\. \*\*Integration Bus\*\* (connectors, pipelines, transforms, observability)  



---



\## S) MODULARITY GUARANTEES

\- \*\*Manifest schema validation\*\*.  

\- \*\*Dependency graph checks\*\* (no cycles, no orphan consumers).  

\- \*\*Capability lint\*\* (must resolve to providers).  

\- \*\*Per-module OpenAPI fragments\*\* merged into master spec.  

\- \*\*Breaking-change guard\*\* in CI.  

\- \*\*Namespaced migrations/settings/RBAC/audit\*\*.  

\- \*\*Hot enable/disable\*\*, graceful degrade.  

\- \*\*Health checks\*\* per module.  



---



\# 📑 Deliverables Summary

\- \*\*Bootstrap Installer + Setup Wizard\*\* (Phase 1–2).  

\- \*\*Admin/User Documentation\*\* (ongoing; Phase 5 baseline, Phase 7 complete).  

\- \*\*CI/CD Guardrails\*\* (Phase 1).  

\- \*\*Core app\*\* (Phase 4).  

\- \*\*Future modules\*\* staged after v1.  



---



