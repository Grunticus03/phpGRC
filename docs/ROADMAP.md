# phpGRC — ROADMAP (Aligned to Charter v1.1)



> Source of truth for phase gating. Each checkbox must be merged & deployed with green guardrails before moving on.



## Phase 0 — Docs-first foundation

- [x] Charter v1.1 committed

- [x] Start ROADMAP.md (this file)

- [ ] Seed BACKLOG.yml (modules/features)

- [ ] Create CAPABILITIES.md (initial matrix)

- [ ] Add RFC template `docs/rfcs/000-template.md`



## Phase 1 — Guardrails + Setup baseline (minimal, no features)

- [x] Server deploy connection (GitHub Actions → rsync → `releases/` + `current`)

- [x] HTTPS/443 serving live (`/var/www/phpgrc/current/web`)

- [ ] Define CI guardrails skeleton (PHPCS, PHPStan, Psalm, PHPUnit, Enlightn, composer-audit, OpenAPI lint) — *stub only*

- [x] Repo structure confirmation (`/api`, `/web`, `/docs`, `/.github`, `/scripts`) — *no app code yet*

- [ ] Setup Wizard scaffold plan (stub doc)



## Phase 2 — Auth/Routing (stubs only, no features enabled by default)

- [ ] Laravel API skeleton (no modules)

- [ ] Sanctum SPA mode scaffold (disabled until SPA exists)

- [ ] TOTP/MFA placeholder config (off by default)

- [ ] Break-glass DB-flag placeholder (off by default)



## Phase 3 — Module foundation

- [ ] ModuleManager + `module.json` schema (no modules enabled)

- [ ] Capabilities registry (no consumers yet)



## Phase 4 — Core app usable (later)

- [ ] Settings UI (DB-backed)

- [ ] Audit Trail

- [ ] Evidence pipeline

- [ ] Exports (CSV/JSON/PDF)



## Phase 5 — Swagger + dashboards (later)

- [ ] OpenAPI served at `/api/openapi.json`

- [ ] Spectral lint in CI



## Phase 6 — Integrations (later)

- [ ] Integration Bus MVP

- [ ] External Auth providers (OIDC/SAML/LDAP/Entra)



## Phase 7 — Release v1.0 (later)

- [ ] Prod deploy workflows

- [ ] Hardening & docs



---



### Current Status (today)

- ✅ Deployed via GitHub Actions and confirmed green.

- ✅ HTTPS/443 serving placeholder from `web/`.

- ▶ Next: choose one *tiny* item (recommendation below).



### Next tiny step (recommendation)

**Create repo structure confirmation** (no code): add empty sentinels for `/api`, `/docs/rfcs/`, and `/scripts/` if missing, then re-deploy to confirm paths exist on the server. *(We’ll do this in the next conversation as a single, small change.)*



