# Audit Configuration Notes

## Keys
- `core.audit.enabled`  
  Enables audit endpoints and background jobs.

- `core.audit.retention_days` (default 365)  
  UI validates 1..730. Purge runtime clamps to [30, 730].

- `core.audit.csv_use_cursor` (default true)  
  CSV export iterates with DB cursor to bound memory.

- `core.rbac.require_auth` (default false in dev/test)  
  Allows anonymous reads for admin screens during development.

- `core.audit.persistence`  
  When false and **no business filters** are supplied, `/api/audit` returns a deterministic **stub-only** envelope for UX development. Implementation may include extra fields; `nextCursor` can be string or null.

## CSV export contract
- `GET /api/audit/export.csv`
- `Content-Type: text/csv`
- `Content-Disposition: attachment; filename="audit-<timestamp>.csv"`
- RFC4180 quoting via `fputcsv`.
- Headers and column order are stable; avoid adding columns without a contract bump.

## Operational guidance
- Prefer cursor mode for large exports.
- Keep retention between 90 and 365 unless policy dictates otherwise.
- Validate OpenAPI before freezing contracts.

