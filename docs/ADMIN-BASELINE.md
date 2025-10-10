# Admin Baseline

## Audience
- Administrators responsible for configuring phpGRC, enforcing policy, and
  reviewing sensitive records.
- Requires the `Admin` role plus the policies granted to that role by default
  (`core.settings.manage`, `core.users.manage`, `core.metrics.view`, etc.).

## Access & Safeguards
- Production overlays **must** enable `core.rbac.require_auth` and Sanctum
  session checks; all Admin routes already sit behind the RBAC middleware stack.
- TOTP multi-factor is required for Admins (`core.auth.mfa.totp.required_for_admin = true`).
- Rate limits are enforced on sensitive endpoints (metrics, reports, exports,
  RBAC) via `GenericRateLimit` middleware; expect HTTP 429 if they trip.
- Every admin action is audited. Audit records can be exported via
  `/api/audit/export.csv` (Admin/Auditor roles).

## Responsibilities & Key Workflows
| Area | Summary | Primary Interfaces |
| --- | --- | --- |
| Admin dashboard | Monitor KPIs (auth activity, evidence MIME distribution, admin activity table) and download the Admin Activity CSV report. | SPA route `/admin/dashboard`; APIs `GET /api/dashboard/kpis`, `GET /api/reports/admin-activity?format=csv`. |
| RBAC management | Create/rename/delete roles; assign roles to users; review policies. | SPA routes `/admin/roles`, `/admin/user-roles`; APIs `GET/POST /api/rbac/roles`, `PUT/DELETE /api/rbac/roles/{role}`, `GET /api/users`, `POST /api/users/{id}/roles`. |
| Settings | View and persist configuration for RBAC, audit, evidence, avatars, metrics, and UI. | SPA route `/admin/settings`; APIs `GET /api/admin/settings`, `PUT /api/admin/settings`. |
| Evidence administration | Review or purge evidence, enforce retention, and run purge commands. | APIs `/api/evidence/*`, console command `audit:purge`; UI `/admin/evidence`. |
| Audit oversight | Review audit trail, export CSV, verify deny audits for RBAC and rate limits. | UI `/admin/audit`; APIs `/api/audit`, `/api/audit/export.csv`. |
| Exports & reports | Trigger data exports (CSV/JSON/PDF) and download completed jobs. | UI `/admin/exports`; APIs `/api/exports/*`. |

## Operational Notes
- Metrics cache defaults to disabled (`core.metrics.cache_ttl_seconds = 0`); the
  Admin Settings page controls the TTL so admins can tune for their environment.
- RBAC persistence is controlled via the `core_settings` table. Switching to
  persist mode is a DB change (`core.rbac.mode = persist` and
  `core.rbac.persistence = true`).
- Unknown role assignments discovered in the database emit `rbac.policy.unknown`
  audits once per policy per process boot; investigate these before rollout.
- Ensure admins hold `core.reports.view` to see dashboard tables; auditors and
  risk managers receive read-only access by default.

## References
- `docs/api/SETTINGS.md` — settings payload, validation, and audit behaviour.
- `docs/phase-5/PHASE-5-DASHBOARDS-AND-REPORTS.md` — KPI and report details.
- `docs/phase-5/RBAC-USER-SEARCH.md` — paginated RBAC user search contract.
- `docs/ops/METRICS-RUNBOOK.md` — operational runbook for KPI endpoints.
- `docs/api/AUDIT.md`, `docs/api/EVIDENCE.md`, `docs/api/EXPORTS.md` — Admin API specifics.
