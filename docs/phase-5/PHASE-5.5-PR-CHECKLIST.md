# Theming & Layout — Phase 5.5 PR Checklist

## Gates
- OpenAPI updated for `/settings/ui`, `/me/prefs/ui`, `/settings/ui/brand-assets`, `/settings/ui/themes*` with examples.
- Spectral + openapi-diff pass.
- PHPStan/Psalm/PHPUnit green, Playwright snapshots recorded for Slate/Flatly/Darkly.

## RBAC + Audit
- Only `role_admin` or `admin.theme` hits `/settings/ui` and theme import routes.
- Audits emitted: `ui.theme.updated`, `ui.theme.overrides.updated`, `ui.brand.updated`, `ui.nav.sidebar.saved`, `ui.theme.pack.imported|deleted|enabled|disabled`.
- Sensitive bytes not stored in audit meta.

## Settings & Prefs
- Global settings persisted in DB; avatars on disk only.
- User prefs persisted: theme, mode, overrides, sidebar {collapsed,width,order}.
- Force-global respected; user light/dark allowed when theme supports both.

## Branding
- Upload validations: svg/png/jpg/jpeg/webp; ≤ 5 MB; MIME sniff; SVG sanitized.
- Favicon derive fallback works; disable footer logo option works.

## Theme Packs
- Zip import guardrails enforced (size, types, depth, filecount, ratio).
- JS/HTML scrubbed and not executed in 5.5. Manifest written.
- Delete purges DB and files; users fall back to default.
- Rate-limit: 5 imports per 10 minutes per admin.

## Layout & UX
- Top navbar shows core modules; logo sizing rules satisfied.
- Sidebar resizing bounds enforced; customization mode flow has Save/Cancel/Default/Exit and merge rules.

## Accessibility & Motion
- Contrast AA verified on key surfaces.
- `prefers-reduced-motion` honored; motion presets effective.

## No-FOUC
- Boot script sets `<html data-theme data-mode>` before CSS; SSR reload verified.

## Manual QA
- Human test checklist executed and returned with pass/fail notes; issues filed.

## Notices
- Bootstrap/Bootswatch license texts included; NOTICE updated.
