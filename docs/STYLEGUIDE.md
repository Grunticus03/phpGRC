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

## Design Tokens & Theming (Phase 5.5)
- Token categories exposed:
  - `color` (RGBA; primary/secondary/surface/text/semantic)
  - `shadow` presets: `none | default | light | heavy | custom(validated box-shadow)`
  - `spacing` presets: `narrow | default | wide` (base 4/8/12 px)
  - `typeScale` presets: `small | medium | large` (1.125/1.20/1.25)
  - `motion` presets: `none | limited | full` (durations clamped; large effects off for `none`)
- CSS variables:
  - Names: `--ui-color-*, --ui-shadow-*, --ui-space-*, --ui-type-scale, --ui-motion-*`
  - Theme CSS loads first, variables override last; no arbitrary CSS text in settings.
- Boot sequence:
  - Inline boot script reads cookie and sets `<html data-theme data-mode>` before CSS to prevent FOUC.
- Bootswatch:
  - Ship full set. Default theme is `slate`. Only one stylesheet is active at a time.
  - App’s Bootstrap JS continues to function. Third-party theme JS is not executed in 5.5.

## Global Layout (Phase 5.5)
- Header:
  - Brand logo top-left. Acts as Home. ~16px vertical padding. ≥40px tall.
  - Core modules in horizontal navbar (alphabetical by default).
  - User menu top-right with profile/lock/logout.
- Sidebar:
  - Contains non-core modules. Collapsible. Resizable between 50px and 50% viewport width.
  - Customization mode via long press:
    - Buttons: Save, Cancel, Default, Exit (unsaved-change prompt).
    - New modules append alphabetically below user-defined block.
- Accessibility:
  - WCAG 2.2 AA contrast enforced. `:focus-visible` visible. Honors `prefers-reduced-motion`.

## Branding (Phase 5.5)
- Assets:
  - Primary, Secondary, Header, Footer logos; Favicon; Title text.
  - Allowed types: svg/png/jpg/jpeg/webp. Max 5 MB each. SVG sanitized. MIME sniff required.
- Defaults:
  - Favicon derived from Primary if not supplied.
  - Footer uses Primary when Footer absent unless disabled.
  - Title text default: `phpGRC — <module>`.

## Theme Pack Import (Phase 5.5)
- Upload `.zip` ≤ 50 MB. Allowed entries: `.css .scss .woff2 .png .jpg .jpeg .webp .svg .map .js .html`.
- JS/HTML stored but not executed in 5.5; scrubbed and recorded in manifest.
- Safe unzip: no traversal/symlinks; depth ≤10; files ≤2000; compression ratio guard.
- Rate limit: 5 imports per 10 minutes per admin.
- Licenses:
  - License file required in the pack; record `name` and path in manifest and NOTICE updates.

