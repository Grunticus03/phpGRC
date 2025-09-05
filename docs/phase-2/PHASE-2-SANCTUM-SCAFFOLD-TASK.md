# Phase 2 — Sanctum SPA Mode Scaffold (disabled by default)

## Instruction Preamble
- **Date:** 2025-09-04
- **Phase:** 2
- **Task:** Sanctum SPA mode scaffold
- **Goal:** Wire Laravel Sanctum for future SPA session auth while keeping it inert by default.
- **Constraints:** Docs-first, placeholders only, no real auth. CI stays green. No DB I/O.

## Scope
- Add Sanctum guard wiring and middleware references.
- Add cookie/session headers notes for SPA.
- Keep stateful domains empty and feature toggled off.
- Provide env/doc hints for later enablement.

## Out of Scope
- Real login/session issuance.
- CSRF integration on SPA.
- Personal access tokens.
- Any RBAC or persistence.

## Deliverables
- Updated `/api/config/auth.php` with commented `api` guard using `sanctum`.
- Updated `/api/config/sanctum.php` with scaffolded keys and comments for SPA.
- New middleware alias registration stub (commented) in `/api/app/Http/Kernel.php` section notes (doc block only if file not present).
- README snippet for enabling SPA mode later.

## Acceptance Criteria
1. Repo contains Sanctum guard scaffold, disabled by default.
2. `stateful` list remains empty; no session cookies are issued.
3. Routes continue to return placeholders. No auth enforcement added.
4. CI passes with PSR-12 and static analysis.
5. Clear enablement steps documented (env vars, domains, cookie flags).

## Definition of Done
- No runtime side effects introduced.
- All changes trace to Phase 2 in ROADMAP.
- Session log updated at closeout.

## File Checklist
- `/api/config/auth.php` (add commented `api` guard → `sanctum`)
- `/api/config/sanctum.php` (comments: stateful domains, cookie flags)
- `/docs/auth/SANCTUM-SPA-NOTES.md` (how to enable later)

## Implementation Notes
- Keep `guards['api']` commented to ensure inert behavior.
- Document cookie requirements: `SameSite=Lax`, HTTPS, domain scoping.
- Defer `EnsureFrontendRequestsAreStateful` mention to notes file.

## Risks
- Accidental enablement → mitigate by leaving guards commented and `stateful=[]`.
- Config drift → centralize in notes with exact keys/env names.

---
Next action: confirm, then I will update `auth.php`, `sanctum.php`, and add `docs/auth/SANCTUM-SPA-NOTES.md`.
