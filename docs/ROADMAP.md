# phpGRC — ROADMAP (Aligned to Charter v1.1)

> Source of truth for phase gating. Each checkbox must be merged & deployed with green guardrails before moving on.

---

## Phase 0 — Docs-first foundation ✅ COMPLETE
- [x] Charter v1.1 committed
- [x] Start ROADMAP.md (this file)
- [x] Seed BACKLOG.md (modules/features)
- [x] Create CAPABILITIES.md (initial matrix)
- [x] Add RFC template `docs/rfcs/000-template.md`

---

## Phase 1 — Guardrails + Setup baseline
- [x] Server deploy connection (GitHub Actions → rsync → `releases/` + `current`)
- [x] HTTPS/443 serving live (`/var/www/phpgrc/current/web`)
- [x] CI/CD guardrails skeleton committed (`ci.yml`) — ✅ Green
- [x] Repo structure confirmation (`/api`, `/web`, `/docs`, `/.github`, `/scripts`) — no app code yet
- [x] Installer & Setup Wizard scaffold (backlog CORE-001) — plan + stubs required

---

## Phase 2 — Auth/Routing
- [ ] Laravel API skeleton (no modules yet) ⏳ NEXT TASK
- [ ] Sanctum SPA mode scaffold (disabled until SPA exists)
- [ ] TOTP/MFA placeholder config (off by default)
- [ ] Break-glass DB-flag placeholder (off by default)
- [ ] Admin Settings UI framework (DB-backed, backlog CORE-003)
- [ ] Early stubs for Exports capability (CSV/JSON/PDF) — deferred to Phase 4 delivery

---

## Phase 3 — Module foundation
- [ ] ModuleManager + `module.json` schema
- [ ] Capabilities registry
- [ ] Stubs for Risks, Compliance, Audits, Policies modules

---

## Phase 4 — Core app usable
- [ ] Settings UI (all configs, RBAC)
- [ ] RBAC roles scaffold (Admin, Auditor, Risk Manager, User)
- [ ] Audit Trail
- [ ] Evidence pipeline
- [ ] Exports (CSV/JSON/PDF)
- [ ] Avatars

---

## Phase 5 — Swagger + dashboards
- [ ] OpenAPI served at `/api/openapi.json`
- [ ] Spectral lint in CI
- [ ] Predefined reports & dashboards
- [ ] Admin/User docs baseline

---

## Phase 6 — Integrations
- [ ] Integration Bus MVP (connectors, pipelines, transforms, observability)  
- [ ] External Auth providers (OIDC/SAML/LDAP/Entra)
- [ ] Asset ingestion (CMDB, cloud, IPAM)
- [ ] Indicator framework
- [ ] BCP/DRP workflows, Vendor inventory, Incident logging (per backlog)

---

## Phase 7 — Release v1.0
- [ ] Prod deploy workflows
- [ ] Hardening & docs
- [ ] Release tag `v1.0.0`

---

### Current Status (as of 2025-09-04)
- ✅ Deployed via GitHub Actions and confirmed green.
- ✅ HTTPS/443 serving placeholder from `web/`.
- ✅ CI/CD workflow green (`ci.yml`).
- ▶ Next: finish Phase 1 by scaffolding Installer/Setup Wizard (CORE-001).
