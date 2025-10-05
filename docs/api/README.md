# phpGRC API

- **OpenAPI spec**: `docs/api/openapi.yaml`  
  Served at runtime: `GET /api/openapi.yaml`.
- **Swagger UI**: `GET /api/docs` (loads the spec above).

## Feature docs

- `docs/api/SETTINGS.md` — Admin Settings contract and behavior.
- `docs/api/RBAC.md` — Role catalog and user–role assignment.
- `docs/api/AUDIT.md` — Audit list and CSV export.
- `docs/api/EVIDENCE.md` — Evidence upload/list/show.
- `docs/api/EXPORTS.md` — Export jobs (create/status/download).
- `docs/api/AVATARS.md` — Avatar upload/show.
- `docs/api/ERRORS.md` — Error envelope shapes.

## Auth and RBAC

- When `core.rbac.require_auth=true`, endpoints marked `security: bearerAuth` require a Sanctum PAT or session.
- Route defaults enforce roles/policies when `core.rbac.enabled=true`.
- Capabilities can hard-gate features. Example: `core.exports.generate` for export job creation.

## Linting

- Spec is OpenAPI 3.1. We lint with Redocly in CI and require:
  - non-empty `description` for operations,
  - at least one `4xx` response per operation.
