# phpGRC — ROADMAP (Aligned to Charter v1.1)

> Source of truth for phase gating. Each checkbox must be merged & deployed with green guardrails before moving on.

---

## Phase 0 — Docs-first foundation ✅ COMPLETE
- [x] Charter v1.1 committed
- [x] Start ROADMAP.md
- [x] Seed BACKLOG.md
- [x] Create CAPABILITIES.md
- [x] Add RFC template `docs/rfcs/000-template.md`

---

## Phase 1 — Guardrails + Setup baseline ✅ COMPLETE
- [x] Repo structure established (`/api`, `/web`, `/docs`, `.github`, `/scripts`)
- [x] CI/CD skeleton (`.github/workflows/ci.yml`) green
- [x] Deploy workflow to test target
- [x] HTTPS placeholder served
- [x] CORE-001 installer + setup wizard stubs

---

## Phase 2 — Auth/Routing ✅ COMPLETE
- [x] Laravel API skeleton reachable
- [x] Auth scaffolds (login/logout/me)
- [x] TOTP scaffolds
- [x] Break-glass guard scaffold
- [x] Admin Settings UI framework (skeleton)
- [x] Early Exports stub endpoints

---

## Phase 3 — Module foundation ✅ COMPLETE
- [x] ModuleManager + `module.json` schema
- [x] Capabilities registry
- [x] Stubs for Risks, Compliance, Audits, Policies modules

---

## Phase 4 — Core app usable ⏳ IN PROGRESS
- [x] Settings — echo + validation stubs
- [x] RBAC — Sanctum PAT guard; route enforcement; JSON 401/403 contract
- [x] RBAC — role IDs standardized to human-readable slugs
- [x] Audit — listing, categories helper, retention echo
- [x] Evidence — multipart validate (size/mime via config)
- [x] Evidence persistence: storage + sha256 + listing + headers
- [x] Audit persistence: write path + retention enforcement (≤ 2 years)
- [x] API docs for Settings/Audit/Evidence + common errors
- [x] Feature tests for Settings/Audit/Evidence + RBAC middleware tagging
- [ ] RBAC fine-grained policies and UI role management
- [x] Exports job model + generation (CSV/JSON/PDF) + download with headers
- [x] Settings persistence + audit logging of applied changes

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
- [ ] BCP/DRP workflows, Vendor inventory, Incident logging

---

## Phase 7 — Release v1.0
- [ ] Prod deploy workflows
- [ ] Hardening & docs
- [ ] Release tag `v1.0.0`

---

### Current Status (as of 2025-09-11)
- ✅ CI green on main; all tests passing.
- ✅ RBAC enforcement active; role IDs locked to slugs.
- ✅ Audit & Evidence persistence complete.
- ✅ Exports model and generation complete.
- ⏳ Fine-grained RBAC policies remain.