# User Baseline

## Audience
- Non-admin practitioners operating inside phpGRC: investigators, auditors, and
  contributors assigned the default `User`, `Risk Manager`, or `Auditor` roles.
- Access is granted through RBAC policies; production environments should run
  with `core.rbac.require_auth = true`.

## Authentication & Session Flow
- Sign-in uses the `/auth/login` page; Sanctum cookies back the SPA session.
- TOTP MFA is optional for non-admin roles but can be enforced per deployment.
- Rate limiting is active (5 failed attempts → temporary 429 lock by default).
- Break-glass is disabled by default; enable only for incident recovery.

## Role Snapshot
| Role | Default Capabilities | Notes |
| --- | --- | --- |
| User | `core.evidence.view` | Read-only evidence access plus profile/avatars. |
| Risk Manager | `core.evidence.manage`, `core.exports.generate`, `core.metrics.view`, `core.audit.view`, `core.reports.view` | Can upload/delete evidence, request exports, and view dashboards. |
| Auditor | `core.audit.view`, `core.audit.export`, `core.metrics.view`, `core.reports.view`, `core.evidence.view` | Audit-focused; can export audit CSV but cannot alter settings or evidence. |

Policies can be tuned via Admin Settings (`core_settings` table); the defaults
above reflect Phase 5 persistence.

## Everyday Tasks
- **Login & MFA** — Access at `/auth/login`; confirm MFA enrollment when the
  deployment requires it.
- **Evidence validation/upload** — UI route `/evidence`; API
  `POST /api/evidence`. Phase 5 ships the validation stub; future phases add
  storage.
- **Evidence browsing** — `/admin/evidence` UI is shared across roles; RBAC
  hides destructive actions from read-only users.
- **Audit review (Auditor/Risk Manager)** — `/admin/audit` UI; API
  `GET /api/audit`. Use filters (`category`, `action`, `actor_id`, etc.) to
  scope activity.
- **Exports (Risk Manager)** — `/admin/exports` UI; APIs under `/api/exports`.
  Jobs stream CSV/JSON/PDF, with audit events recorded for each request.
- **Dashboards (Risk Manager/Auditor)** — `/admin/dashboard`; data from
  `GET /api/dashboard/kpis`. Read-only view of auth trends, evidence MIME mix,
  and admin activity table.
- **Profile & avatars (all roles)** — `/profile/avatar` UI; API
  `POST /api/avatars`. Updates are optional and validated against allowed MIME
  types (`webp`).

## Self-Service Checks
- Verify personal access token status via `/auth/me` (API) when building
  integrations.
- Use `/api/rbac/users/search` for directory lookups (Admin policy required for
  the endpoint; returning data is visible to downstream tooling).
- Personal settings (time format, etc.) inherit from Admin-configured defaults;
  per-user overrides ship with Phase 5.5 theming work.

## References
- `docs/api/EVIDENCE.md` — evidence upload/list/show contracts.
- `docs/api/AUDIT.md` — audit list/export contracts.
- `docs/api/EXPORTS.md` — export job lifecycle and envelopes.
- `docs/auth/LOGIN.md` (when available) — login/MFA specifics.
- `docs/phase-5/PHASE-5-DASHBOARDS-AND-REPORTS.md` — KPI behaviours.
