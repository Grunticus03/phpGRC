# @phpgrc:/PLAYBOOK.md
# phpGRC Playbook

The Playbook defines **how work is conducted** in phpGRC.  
It is binding on all sessions, files, commits, and releases.

---

## 1. Session Protocols
- **Session Header (start of chat):** Must include Date/Phase/Step, Context, Goal, Constraints.  
  - Add: Use absolute dates (YYYY-MM-DD). No relative dates.
- **Session Footer (end of chat):** Must include Deliverables, Phase/Step status, Next actions (you/me).  
  - Add: Include “Session Transfer Packet” when work spans sessions.
- **Instruction Preamble:** ChatGPT always provides context/order/acceptance before generating files.  
- **Full-file outputs only:** All file responses include a header discriminator (`# @phpgrc:/path/to/file`).  
- **Scope creep prevention:** All deliverables must trace to Charter or Backlog; new scope requires Charter amendment.
- **API/Web split:** Cross-cutting features open **two issues** (API first, then Web UI) under one parent milestone.

---

## 2. File Management Protocols
- **Paths:** Always repo-root-relative (e.g., `/api/...`, `/docs/...`).  
- **Header discriminator:** Required at top of every file output.  
- **Multi-file edits:** User must provide all requested files before ChatGPT outputs updates.  
- **No partials:** ChatGPT never outputs snippets — only full files.  
- **Deterministic mode:** All outputs generated with temperature=0, top_p=0.
- **Add:** Do **not** delete out-of-scope items; mark as “Out of scope” and add a child note.

---

## 3. CI/CD & Guardrails
- **Workflows:** One CI workflow (`ci.yml`) and one Deploy workflow (`deploy.yml`) only.  
- **All guardrails green = merge.** Blocking checks:  
  - PHPCS (PSR-12)  
  - PHPStan L9 (do not downgrade).  
  - Psalm (no-new-issues)  
  - Enlightn (fail high/critical)  
  - composer-audit (fail high/critical, warn medium)  
  - Spectral OpenAPI lint + diff  
  - commitlint (Conventional Commits)  
  - CODEOWNERS review  
  - **Add:** Node: Vitest unit tests green  
  - **Add:** ESLint (no errors), TypeScript `--noEmit` typecheck
- **Branch protection:** `main` requires PRs, no force-push, stale reviews dismissed.  
- **Commit convention:** Conventional Commits enforced.
- **Add:** CI forbids `env(` outside `/api/config/*` and `/web/vite*` to enforce DB-backed runtime settings.

---

## 4. Documentation Discipline
- **Every feature must trace to BACKLOG.yml ID.**  
- **RFCs required for new modules.**  
- **Docs are code:** Charter, Roadmap, Backlog, Capabilities, RFCs must stay in sync.  
- **Session Footers:** may be pasted into `docs/SESSION-LOG.md` if permanent log is desired.
- **Add:** Release Notes and Security Review notes updated **with exact endpoints and policies** gated.

---

## 5. Delivery & Review Protocols
- **Artifacts before implementation:** Docs/Backlog entry must exist before code is produced.  
- **Instruction Preamble before code.**  
- **You = QA/infra gatekeeper.**  
- **Me = sole code contributor.**
- **Add:** For API+Web work, **land API first** behind RBAC, then wire Web UI in a follow-up PR.

---

## 6. Governance & Anti-Creep
- **Changes require Charter amendment.**  
- **Bugfixes allowed.**  
- **Nice-to-haves** deferred unless formally added.  
- **Future modules** live in Backlog under `future:`.
- **Add:** OpenAPI must not change without an approved diff plan; internal endpoints may exist outside OpenAPI when documented in Phase notes.

---

## 7. Release Protocols
- **Tags:** `v0.x.y-phaseN-stepM` until v1.0.0 milestone.  
- **Phase completion:** requires all backlog items merged & tagged.  
- **Release notes:** each release includes backlog IDs delivered.  
- **Artifacts:** each release includes `phpgrc-build.tar.gz`.
- **Add:** Include DB migrations checksum and `core_settings` baseline verification steps.

---

## 8. Environment & Deployment Discipline
- **Deploy path convention:** `/opt/phpgrc/{releases,shared,current}`.  
- **DB-only system of record.**  
  - Add: **All non-connection settings** persist in DB (`core_settings`). `.env` never allowed.
  - Add: Provider overlays settings at boot; runtime reads come from config populated from DB.
- **Domain-agnostic deploy.**  
- **Secrets management:** Deploy user restricted; DB config is the only file on disk.
- **Add (Apache/Laravel):**
  - `/api/*` routed to `/api/public/index.php` via `Alias` + `AllowOverride All` (enable `mod_rewrite`).  
  - Prefer `ProxyPassMatch` to PHP-FPM; or mod_php if constrained.  
  - Enable: `rewrite`, `proxy`, `proxy_http`, `headers`, `ssl`.  
  - Route/cache clear on deploy: `php artisan config:clear && route:clear && route:cache`.
- **Add (Cache in tests):** Default to `file` to avoid DB cache table errors unless a cache table migration is present.

---

## 9. Testing Discipline
- **Backend:** PHPUnit feature tests for RBAC (401/403), validation envelopes, persistence semantics, metrics clamping.  
- **Frontend:** Vitest + Testing Library. Avoid brittle text selectors; prefer roles/labels.  
- **Data:** Seed minimal rows for dashboards and audits.  
- **Add:** When settings move from `.env` → DB, update tests to assert **DB overrides** and remove `.env` coupling.

---

## 10. Auditing & Telemetry
- **One-audit-per-request** invariant for denies.  
- **Settings changes** audited as `settings.update` with `meta.changes[{key,old,new,action}]`. No secrets or raw bytes.  
- **RBAC deny codes** labeled in UI via mapping table; keep mapping in a single source.

---

## 11. Issue Hygiene
- **API/Web pairing:** file two issues when UX depends on new API data.  
- **Parent milestone** tracks the user-facing outcome.  
- **Definition of Done:** both API and Web issues closed, docs updated, CI green, audit paths verified.

---

## Out of Scope (kept for visibility)
- **Lower PHPStan levels:** not permitted.  
- **Direct `.env` toggles for runtime behavior:** moved to DB persistence.
