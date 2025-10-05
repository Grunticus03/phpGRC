# Phase 2 — Early Exports Stubs (placeholders only)

## Instruction Preamble
- **Date:** 2025-09-05
- **Phase:** 2
- **Task:** Exports stubs
- **Goal:** Provide API shape for exports without jobs, files, or DB.

## Scope
- `POST /api/exports` → 202 with `jobId` placeholder.
- `GET /api/exports/{jobId}/status` → static status payload.
- `GET /api/exports/{jobId}/download` → 404 `EXPORT_NOT_READY`.

## Out of Scope
- Queues, storage, DB, auth, RBAC, real export generation.

## Deliverables
- Controllers under `App\Http\Controllers\Export\*`.
- Routes mounted under `/api/exports`.

## Acceptance Criteria
1. Routes compile and return placeholders.
2. No state changes or storage I/O.
3. CI remains green.

## Definition of Done
- Full-file outputs with header discriminator.
- Session log updated at closeout.
