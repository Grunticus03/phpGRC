# phpGRC API Docs

- **OpenAPI spec**: `docs/api/openapi.yaml`  
  Served at runtime from **GET** `/api/openapi.yaml`.

- Feature docs:
  - `docs/api/SETTINGS.md` – Admin Settings contract and behavior (validate-only by default; `apply` to persist).
  - `docs/api/EVIDENCE.md` – Evidence upload/list/show.
  - `docs/api/AUDIT.md` – Audit list and CSV export.
  - `docs/api/ERRORS.md` – Error envelope shapes.

## Linting

We lint against OpenAPI 3.1. The spec includes a license identifier and defines root-level `security: []` to satisfy common linters. Non-blocking informational warnings may be deferred.
