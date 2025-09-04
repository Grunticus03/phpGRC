# phpGRC Playbook

The Playbook defines **how work is conducted** in phpGRC.  
It is binding on all sessions, files, commits, and releases.

---

## 1. Session Protocols
- **Session Header (start of chat):** Must include Date/Phase/Step, Context, Goal, Constraints.  
- **Session Footer (end of chat):** Must include Deliverables, Phase/Step status, Next actions (you/me).  
- **Instruction Preamble:** ChatGPT always provides context/order/acceptance before generating files.  
- **Full-file outputs only:** All file responses include a header discriminator (`# @phpgrc:/path/to/file`).  
- **Scope creep prevention:** All deliverables must trace to Charter or Backlog; new scope requires Charter amendment.

---

## 2. File Management Protocols
- **Paths:** Always repo-root-relative (e.g., `/api/...`, `/docs/...`).  
- **Header discriminator:** Required at top of every file output.  
- **Multi-file edits:** User must provide all requested files before ChatGPT outputs updates.  
- **No partials:** ChatGPT never outputs snippets — only full files.  
- **Deterministic mode:** All outputs generated with temperature=0, top_p=0.

---

## 3. CI/CD & Guardrails
- **Workflows:** One CI workflow (`ci.yml`) and one Deploy workflow (`deploy.yml`) only.  
- **All guardrails green = merge.** Blocking checks:  
  - PHPCS (PSR-12)  
  - PHPStan L5  
  - Psalm (no-new-issues)  
  - Enlightn (fail high/critical)  
  - composer-audit (fail high/critical, warn medium)  
  - Spectral OpenAPI lint + diff  
  - commitlint (Conventional Commits)  
  - CODEOWNERS review  
- **Branch protection:** `main` requires PRs, no force-push, stale reviews dismissed.  
- **Commit convention:** Conventional Commits enforced.

---

## 4. Documentation Discipline
- **Every feature must trace to BACKLOG.yml ID.**  
- **RFCs required for new modules.**  
- **Docs are code:** Charter, Roadmap, Backlog, Capabilities, RFCs must stay in sync.  
- **Session Footers:** may be pasted into `docs/SESSION-LOG.md` if permanent log is desired.

---

## 5. Delivery & Review Protocols
- **Artifacts before implementation:** Docs/Backlog entry must exist before code is produced.  
- **Instruction Preamble before code.**  
- **You = QA/infra gatekeeper.**  
- **Me = sole code contributor.**

---

## 6. Governance & Anti-Creep
- **Changes require Charter amendment.**  
- **Bugfixes allowed.**  
- **Nice-to-haves** deferred unless formally added.  
- **Future modules** live in Backlog under `future:`.

---

## 7. Release Protocols
- **Tags:** `v0.x.y-phaseN-stepM` until v1.0.0 milestone.  
- **Phase completion:** requires all backlog items merged & tagged.  
- **Release notes:** each release includes backlog IDs delivered.  
- **Artifacts:** each release includes `phpgrc-build.tar.gz`.

---

## 8. Environment & Deployment Discipline
- **Deploy path convention:** `/opt/phpgrc/{releases,shared,current}`.  
- **DB-only system of record.**  
- **Domain-agnostic deploy.**  
- **Secrets management:** Deploy user restricted; DB config is the only file on disk.
