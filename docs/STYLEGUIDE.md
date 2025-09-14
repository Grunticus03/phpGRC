# phpGRC Style Guide

- Markdown: 80–120 col soft wrap; headings start at H1 per file.
- YAML/JSON: 2-space indent; LF line endings.
- Commit messages: conventional commits (`feat`, `fix`, `docs`, `chore`, `refactor`, `ci`, `build`, `perf`, `test`, `style`).

## IDs and Slugs
- IDs visible in UI or APIs must be human-readable slugs.
- Role IDs: `role_<slug>` using lowercase ASCII, `_` separator.
- Collision rule: append `_<N>` where `N` starts at 1 and increments.
- Use ULIDs/integers only for opaque IDs not shown to users.

## API & OpenAPI Authoring
- OpenAPI version: **3.1**. File at `docs/api/openapi.yaml`. Served at `GET /api/openapi.yaml`. UI at `GET /api/docs`.
- Every operation:
  - Must have a non-empty `summary` and `description`.
  - Must include at least one **`4xx`** response and a success `2xx` (or `3xx` if appropriate).
  - Must set an `operationId` and one or more `tags`.
- Components:
  - Reuse schemas via `#/components/schemas/*`. Names are **PascalCase**.
  - Standard envelopes: `OkEnvelope`, `Error422`, `SettingsEnvelope`, etc.
- Content types:
  - JSON APIs use `application/json`.
  - File uploads use `multipart/form-data`.
  - CSV downloads use `text/csv` (no charset).
  - Images use exact `image/webp` for avatars.
- Auth:
  - Use `security` at root when global, override per operation as needed.
  - Sanctum PATs are represented as `bearerAuth` when we declare security schemes.
- Errors:
  - Validation errors standardize on `{ "ok": false, "code": "VALIDATION_FAILED", "errors": {...} }` unless a legacy route specifies otherwise.
- Backward compatibility:
  - Additive changes only. Removals or shape changes require a major version.
  - CI runs **Redocly** lint and **openapi-diff** breaking-change gate on PRs.
- Examples:
  - Provide realistic examples in docs under `docs/api/*.md`. Use fenced `json` blocks.

## PHP
- PHP 8.3. Typed properties. `strict_types=1` at file top.
- Controllers return typed `JsonResponse|Response`.
- Validation via `FormRequest` classes. On failure, return spec’d error envelopes.
- Middleware owns cross-cutting concerns (RBAC, capability gates).
- Never swallow exceptions except where explicitly non-fatal by design (e.g., audit logging).

## Frontend
- React 18, Vite, TypeScript.
- Keep API contracts in sync with `openapi.yaml`. Generate types in later phase.

## Security
- Enforce `X-Content-Type-Options: nosniff` on file responses.
- Validate and clamp all pagination and filter parameters.
- Favor deny-by-default for policies; allow-by-default only in stub modes defined by spec.
