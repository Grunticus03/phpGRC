# Theming & Layout — Phase 5.5 PR Checklist

## Gates
- [x] OpenAPI updated for `/settings/ui`, `/me/prefs/ui`, `/settings/ui/brand-assets`, `/settings/ui/themes*` with examples.
- [x] Spectral + openapi-diff pass.
- [x] PHPStan/Psalm/PHPUnit green.
- [ ] Playwright snapshots recorded for Slate/Flatly/Darkly.

## RBAC + Audit
- [x] Only `role_admin` or `role_theme_manager` (capability `admin.theme`) hits `/settings/ui` and theme import routes; read-only endpoints allow `role_theme_auditor`.
- [x] Audits emitted: `ui.theme.updated`, `ui.theme.overrides.updated`, `ui.brand.updated`, `ui.nav.sidebar.saved`, `ui.theme.pack.imported|deleted|enabled|disabled`.
- [ ] Sensitive bytes not stored in audit meta.

## Settings & Prefs
- [x] Global settings persisted in DB; avatars on disk only.
- [x] User prefs persisted: theme, mode, overrides, sidebar {collapsed,width,order}.
- [x] Force-global respected; user light/dark allowed when theme supports both.

## Branding
- [x] Upload validations: svg/png/jpg/jpeg/webp; ≤ 5 MB; MIME sniff; SVG sanitized.
- [x] Favicon derive fallback works; disable footer logo option works.

## Theme Packs
- [ ] Zip import guardrails enforced (size, types, depth, filecount, ratio).
- [ ] JS/HTML scrubbed and not executed in 5.5. Manifest written.
- [ ] Delete purges DB and files; users fall back to default.
- [ ] Rate-limit: 5 imports per 10 minutes per admin.

## Layout & UX
- [ ] Top navbar shows core modules; logo sizing rules satisfied.
- [ ] Sidebar resizing bounds enforced; customization mode flow has Save/Cancel/Default/Exit and merge rules.

## Accessibility & Motion
- [ ] Contrast AA verified on key surfaces.
- [ ] `prefers-reduced-motion` honored; motion presets effective; monthly locale smoke (`ar`, `ja-JP`) recorded.

## No-FOUC
- [ ] Boot script sets `<html data-theme data-mode>` before CSS; SSR reload verified.

## Manual QA
- [ ] Human test checklist executed and returned with pass/fail notes; issues filed.

## Notices
- [ ] Bootstrap/Bootswatch license texts included; NOTICE updated.

> **Playwright commands:** `npm --prefix web run test:e2e:update` to refresh snapshots, `npm --prefix web run test:e2e` for CI runs. Default preview host: `http://127.0.0.1:4173`.
