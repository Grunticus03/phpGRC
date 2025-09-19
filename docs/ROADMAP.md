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

## Phase 4 — Core app usable ✅ COMPLETE (frozen 2025-09-19)
- [x] Settings — echo + validation stubs
- [x] RBAC — Sanctum PAT guard; route enforcement; JSON 401/403 contract
- [x] RBAC — role IDs standardized to human-readable slugs
- [x] RBAC — admin UI for role list/create and user-role assign
- [x] Audit — listing, categories helper, retention echo
- [x] Audit — CSV export with exact `Content-Type: text/csv` and cursor streaming
- [x] Evidence — multipart validate (size/mime via config)
- [x] Evidence persistence: DB storage + sha256 + listing + headers + conditional GET + hash verification
- [x] Audit persistence: write path + retention enforcement (≤ 2 years)
- [x] API docs for Settings/Audit/Evidence + common errors
- [x] Feature tests for Settings/Audit/Evidence + RBAC middleware tagging
- [x] Exports job model + generation (CSV/JSON/PDF) + download with headers
- [x] Settings persistence + audit logging of applied changes
- [x] Stub-path audit response covered by tests
- [x] CSV large-dataset smoke for SQLite
- [x] Ops docs: retention runbook + audit config notes
- [x] OpenAPI 0.4.6 validated and served
- [x] Static analysis: PHPStan level 9 enforced in CI

---

## Phase 5 — Swagger + dashboards + RBAC policies
- [x] OpenAPI served at `/api/openapi.yaml` and Swagger UI at `/api/docs`
- [x] OpenAPI lint in CI (Redocly)
- [x] Breaking-change gate (openapi-diff) in CI
- [ ] Fine-grained RBAC policies (PolicyMap/Evaluator) and role management UI hardening
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

### Current Status (as of 2025-09-19)
- ✅ Phase 4 frozen; CI green; contracts locked; OpenAPI 0.4.6 validated.
- ✅ RBAC enforcement active; admin UI shipped.
- ✅ Audit & Evidence persistence complete; CSV export streaming with bounded memory.
- ✅ Exports model and generation complete.
- ✅ CI lint (Redocly) and breaking-change gate (openapi-diff).
- ✅ Static analysis: PHPStan level 9 enforced in CI.
- ⏳ Fine-grained RBAC policies move to Phase 5.
