# Phase 5.5 Kickoff — Theming & Layout

Status: Planned — target start 2025-10-01  
Contract baseline: OpenAPI 0.4.7 (additive only)  
Guardrails: PHPStan L9, Psalm, PHPUnit, ESLint, TypeScript `--noEmit`, Vitest, Playwright, Redocly lint, openapi-diff

## Scope

Deliver the full Phase 5.5 backlog (`THEME-001` → `THEME-009`) covering Bootswatch runtime theming, admin configurator, per-user preferences, branding uploads, global layout polish, theme pack import, settings APIs, accessibility gates, and no-FOUC boot flow. All functionality must respect DB-backed settings, RBAC enforcement, and audit invariants established in earlier phases.

## Objectives

1. **Bootswatch set + no-FOUC**  
   Ship all Bootswatch themes locally with a pinned version, default Slate (dark) / Flatly (light), and inline boot script that applies the active theme before first paint.
2. **Theme configurator + tokens**  
   Admins (or delegated theme managers) can adjust design tokens with AA contrast guards, RBAC, and audits.
3. **Per-user preferences**  
   Persist theme/mode/overrides/sidebar preferences with admin force-global override respected.
4. **Branding assets**  
   Upload/sanitize logos + favicon, store metadata in DB, serve via signed URLs, and audit changes.
5. **Global layout polish**  
   Navbar, sidebar sizing/customization, profile menu, accessibility semantics, and keyboard nav updates.
6. **Theme packs**  
   Safe ZIP import + manifest registration, rate limits, metadata storage, delete fallback, optional launch toggle.
7. **Settings & API surface**  
   `/settings/ui`, `/me/prefs/ui`, `/settings/ui/brand-assets`, `/settings/ui/themes*`, optional `/admin/themes` manifest, OpenAPI updates + examples.
8. **QA + accessibility**  
   Enforce Playwright snapshots, axe scans, manual theming checklist, and NOTICE/licensing updates.

## Decisions

### Step ordering & ownership

Use numbered steps (`5.5.x`) with single ownership and explicit blockers. Web stories depend on API delivery unless marked “stub OK”. All steps require code, docs, and tests with green CI.

```
Step   Owner  Title                               Blocked by
-----  -----  ----------------------------------  -----------
5.5.1  API    Settings read endpoint              —
5.5.2  API    Settings write endpoint             5.5.1
5.5.3  Web    Settings UI scaffold                5.5.1
5.5.4  Web    Settings UI wire-up                 5.5.2, 5.5.3
5.5.5  API    RBAC policy map read                —
5.5.6  Web    Role permissions matrix             5.5.5
5.5.7  API    Capability exposure                 —
5.5.8  Web    Capability badges                   5.5.7
5.5.9  Web    Sidebar UX polish                   5.5.3
5.5.10 Web    Auth UX hardening                   5.5.3
5.5.11 API    Rate-limit headers                  —
5.5.12 QA     Cross-cutting E2E + accessibility   5.5.4, 5.5.6, 5.5.8, 5.5.9, 5.5.10, 5.5.11
```

Create one issue per step titled `[5.5.x] Owner — Name` with labels `phase:5.5`, `owner:api|web|qa`, and `blocked-by:5.5.y` when applicable.

### Persistence model

- Tables: `ui_settings` (key/value JSON with typed metadata) and `ui_assets` (branding + theme files).  
- Keys: dot.case under `ui.*` (e.g., `ui.theme.name`, `ui.brand.primary_logo_asset_id`).  
- Files: stored under `/storage/app/brand/<ulid>_<slug>.<ext>` with SHA-256, served via signed route.  
- Settings mutations use replace semantics with `If-Match` ETag (weak) and emit a single audit event.  
- Upload endpoints return asset descriptors; settings store referenced asset ULIDs only.  
- OpenAPI envelopes expose `config.ui.*` values with consistent types and audit metadata.

### Bootswatch + theme packs

- Bundle all Bootswatch themes locally (pinned `bootswatch@5.3.3`), hashed file names, manifest JSON, and inline boot script to avoid FOUC.  
- Default themes: Slate (dark), Flatly (light). Manifest enumerates available themes.  
- Theme packs upload flow optional at launch but infrastructure lands in Phase 5.5.  
- NOTICE includes Bootstrap + Bootswatch MIT entries; uploaded packs append vendor licenses.  
- `/admin/themes` (optional) returns Bootswatch manifest + uploaded packs for clients.

### RBAC defaults

- **New roles:**  
  - `role_theme_manager` → `admin.theme`, `ui.theme.manage`, `ui.branding.manage`, `ui.theme.import`.  
  - `role_theme_auditor` → `ui.theme.view` (read-only).  
- `role_admin` retains all capabilities (including theming).  
- Policy map + evaluator must load new roles; audits include differentiated role IDs.  
- Settings/theming routes require `admin.theme` (or admin). Read-only endpoints allow `ui.theme.view`.

### QA expectations

- Playwright snapshots per PR: Slate + Flatly, desktop 1440×900 and mobile 375×812, eight key screens.  
- Axe-core enforced on snapshots; prefers-reduced-motion variant required.  
- Manual theming checklist executed by QA owner (you) each PR: no FOUC, contrast AA, focus states, toast legibility.  
- Visual baseline changes need reviewer approval and justification in PR description.  
- Artifacts (screenshots, traces, axe reports) retained 14 days.  
- Monthly smoke for `ar` and `ja-JP` locales; `en-US` covered per PR.

## Deliverables

- API controllers, routes, requests, resources, audits, and migrations for UI settings, branding assets, and theme packs.  
- Web SPA updates: boot script, theme loader, admin configurator UI, per-user preference screens, sidebar/profile UX polish, capability indicators.  
- DB migrations for `ui_settings` and `ui_assets`, seed defaults, repository/service layers, caching.  
- OpenAPI 3.1 updates with examples and spectral-compliant schema changes.  
- PHPUnit + Pest coverage for validation, ETag, RBAC, audits, file handling, rate-limit headers.  
- Vitest + Playwright suites for theme application, settings flows, accessibility checks.  
- Docs updates: Style Guide, Settings Guide, Capabilities, ROADMAP, NOTICE (licenses).  
- QA artifacts and manual checklist records.

## Dependencies & Constraints

- No breaking changes to existing routes or OpenAPI schemas (additive only).  
- Continue using DB-backed settings; `.env` remains bootstrap-only.  
- RBAC policy enforcement from Phase 5 remains; new roles integrate with existing PolicyMap caching and audits.  
- Evidence, KPIs, and other Phase 5 endpoints must not regress due to UI settings overlay.  
- Theme pack JS/HTML stored but not executed in Phase 5.5; scrub + manifest required.

## Risks

- Theme pack import security: mitigate with strict allowlist, ZIP guardrails, SVG sanitization, manifest validation, and audit trail.  
- Accessibility regressions across themes: enforce contrast guards, automated axe checks, manual review.  
- RBAC misconfiguration blocking admins: provide safe defaults, migration seeds for new roles, and thorough tests.  
- Performance of settings load: cache overlays with ETag, ensure queries indexed.  
- Visual regressions: manage through snapshots, manifest pinning, and pinning Bootswatch version.

## Milestones

1. **API foundation (5.5.1–5.5.2)** — Settings read/write + persistence + audits.  
2. **Theming configurator (5.5.3–5.5.4)** — Web UI scaffolding + save flows + client validation.  
3. **RBAC & capability surfacing (5.5.5–5.5.8)** — Policy map read, matrix UI, capability badges.  
4. **Layout & auth hardening (5.5.9–5.5.10)** — Sidebar behavior, profile menu, auth UX polish.  
5. **Rate limit headers (5.5.11)** — Align selected routes with generic limit contract.  
6. **QA + accessibility (5.5.12)** — Playwright, axe, manual checklist, docs/licensing wrap-up.

## Acceptance / DoD

- All THEME backlog items closed with linked issues and PRs.  
- CI green with updated Playwright snapshots, axe reports, and manual checklist attachments.  
- OpenAPI lint + diff pass; no breaking changes flagged.  
- RBAC tests cover new roles/capabilities; PolicyMap caches reflect new seeds.  
- Theme settings persist in DB and survive restart; branding assets served with correct headers.  
- No FOUC across supported themes; Bootswatch manifest pinned and documented.  
- NOTICE updated with theming dependencies; audit logs cover every settings/asset change.

## Next Actions

1. QA global layout: capture Playwright snapshots (Slate/Flatly/Darkly), validate drag-and-drop sidebar UX, and document manual checklist outcomes.  
2. Implement no-FOUC boot script prototype and integrate with theme manager cache warmup.  
3. Add automated regression coverage for theme toggle + preview (Admin ▸ Theming, per-user prefs).  
4. Finalize RBAC hand-off (`role_theme_manager`, `role_theme_auditor`) and update CAPABILITIES/NOTICE docs.  
5. Prepare accessibility review (axe scans, reduced-motion behavior) ahead of Phase 5.5 close-out.
