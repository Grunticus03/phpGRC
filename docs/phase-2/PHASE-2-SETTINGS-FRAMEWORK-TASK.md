# @phpgrc:/docs/phase-2/SETTINGS-FRAMEWORK-TASK.md
# Phase 2 — Admin Settings UI Framework (skeleton)

## Instruction Preamble
- **Date:** 2025-09-05
- **Phase:** 2
- **Task:** Admin Settings framework skeleton
- **Goal:** Expose placeholder Admin Settings endpoints for future DB-backed UI. No persistence.

## Scope
- Add `/api/admin/settings` GET (returns config placeholders).
- Add `/api/admin/settings` PUT (accepts payload, returns 202 placeholder).
- No DB reads/writes. No auth enforcement yet.

## Out of Scope
- Real settings persistence and validation.
- RBAC, audit trail, UI forms. Deferred to CORE-003/Phase 4.

## Acceptance Criteria
1. Routes compile and return JSON placeholders.
2. No state change occurs on PUT.
3. CI remains green. No DB touch.

## Definition of Done
- Files adhere to header discriminator.
- Session log updated at closeout.
