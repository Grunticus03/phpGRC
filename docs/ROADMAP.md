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
- [x] Settings — echo + validation stubs (accept spec or legacy payload; normalized)
- [x] RBAC — roles list + no-op middleware
- [x] Audit — spec-shaped events, categories helper, retention echo
- [x] Evidence — multipart validate (size/mime via config)
- [x] Exports — path route `POST /api/exports/{type}` added; legacy body route kept; status echoes `id`
- [x] Avatars — WEBP-only validation; basic dimension check
- [ ] Evidence persistence: storage pathing + sha256 hashing
- [ ] Audit persistence: write path + retention enforcement (≤ 2 years)
- [ ] RBAC policies and DB roles
- [ ] Exports job model + generation (CSV/JSON/PDF)
- [ ] Settings persistence + audit logging

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

### Current Status (as of 2025-09-05)
- ✅ CI/CD green.
- ✅ Phase-4 validation controllers merged.
- ⏳ Persistence and policies pending in Phase-4.
