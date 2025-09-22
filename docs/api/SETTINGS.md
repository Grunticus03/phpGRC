# FILE: docs/SETTINGS.md
# phpGRC Settings Guide
Scope: **UI settings only**. Core Phase-4 Evidence uploads ignore MIME and size validation by policy.

Canonical runtime settings. Stored in DB unless noted. Avatars and theme pack files are on disk.

## Conventions
- Keys use dot.case.
- All writes validate and return `422 VALIDATION_FAILED` on errors.
- Additive changes only; breaking changes require a major version.

> File-upload rules below apply to **UI features** (branding and theme packs), not Evidence uploads in Phase 4.

## Storage
- Global settings: DB table `ui_settings` (key → JSON value).
- Per-user prefs: DB table `user_ui_prefs`.
- Branding assets: DB `brand_assets` (metadata) + disk file.
- Theme packs: DB `themes` (+ optional `theme_assets`) + files under `/public/themes/<slug>/`.
- Avatars: disk only; metadata on the user record.

---

# UI Settings (Phase 5.5)

## Global keys (`/settings/ui`)
- `ui.theme.default` : string — Bootswatch slug. **Default:** `slate`.
- `ui.theme.allow_user_override` : boolean — allow user theme choice. **Default:** `true`.
- `ui.theme.force_global` : boolean — force global theme for everyone; **still allows user light/dark** if theme supports both. **Default:** `false`.
- `ui.theme.overrides` : object<string,string> — token → value. Allowed tokens only; values validated.
- `ui.nav.sidebar.default_order` : string[] — canonical module keys for default sidebar order.
- `ui.brand.primary_logo` : file-ref|null
- `ui.brand.secondary_logo` : file-ref|null
- `ui.brand.header_logo` : file-ref|null
- `ui.brand.footer_logo` : file-ref|null
- `ui.brand.footer_logo_disabled` : boolean — hide footer logo in exports. **Default:** `false`.
- `ui.brand.favicon` : file-ref|null
- `ui.brand.title_text` : string — **Default:** `phpGRC — <module>`

### File Ref shape (read-only)
Stored in `brand_assets`.
```
id, type, path, size, mime, sha256, uploaded_by, created_at
```

## Per-user prefs (`/me/prefs/ui`)
- `theme` : string|null — Bootswatch slug
- `mode` : `"light" | "dark" | null` — explicit beats system; null = follow system
- `overrides` : object<string,string> — same guardrails as global
- `sidebar` : `{ collapsed: boolean, width: number, order: string[] }`

## Tokens & presets
- **color.\*** : RGBA. Enforce WCAG 2.2 AA vs surfaces/text. Full color picker allowed (wheel, hex, RGB, eyedropper). Persist as RGBA.
- **shadow** : one of `none | default | light | heavy | custom`
  - `none` → `none`
  - `default` → `0 1px 2px rgba(0,0,0,.06), 0 1px 3px rgba(0,0,0,.10)`
  - `light` → `0 1px 1px rgba(0,0,0,.04)`
  - `heavy` → `0 10px 15px rgba(0,0,0,.20), 0 4px 6px rgba(0,0,0,.10)`
  - `custom` → validated `box-shadow` only (strict parser; reject unsafe content)
- **spacing** : `narrow | default | wide` → base 4/8/12 px scale
- **typeScale** : `small | medium | large` → 1.125 / 1.20 / 1.25
- **motion** : `none | limited | full`
  - `none` disables nonessential transitions/animations
  - `limited` ~50% durations, no large parallax
  - `full` standard durations (150–300 ms)

### Validation rules for UI assets
- Branding uploads: MIME sniff + extension; `415` on mismatch. Max 5 MB per file.
- Theme-pack import: zip ≤ 50 MB; see Theme Packs section.
- Clamp `sidebar.width` to **min 50 px** and **max 50%** of viewport.
- Enforce types for logos (see Branding) and sizes.

---

# Theme Packs (Phase 5.5)

## Endpoints
- `POST /settings/ui/themes/import` — zip upload (RBAC: `admin.theme`)
- `GET /settings/ui/themes` — list
- `PUT /settings/ui/themes/{slug}` — enable/disable, metadata
- `DELETE /settings/ui/themes/{slug}` — purge theme + files

## Limits & types
- Max zip size: **50 MB**.
- Allowed entries inside zip: `.css .scss .woff2 .png .jpg .jpeg .webp .svg .map .js .html`.
- Safe unzip: block path traversal/symlinks; depth ≤ **10**; files ≤ **2000**; compression ratio guard.
- License file required; record license name/path in manifest and update NOTICE.

## JS/HTML policy (5.5)
- JS/HTML are **stored but not executed**.
- Scrubbed and recorded as **inactive** in manifest.
- CSS is loaded; third-party JS from theme packs is not.

## Security scrub (import)
- CSS: reject `@import` and `url()` to external origins; reject `javascript:` URLs; cap `data:` URLs ≤ 512 KB.
- HTML: strip `<script>`, `<iframe>`, `<object>`, all `on*=` handlers, external form actions, meta refresh.
- JS: reject `eval`, `new Function`, ServiceWorker APIs, and network calls to external origins.
- SVG: sanitize; strip scripts and remote refs.

## Manifest (stored with theme)
```json
{
  "slug": "mytheme",
  "name": "My Theme",
  "source": "custom",
  "version": "x.y",
  "author": "vendor",
  "license": { "name": "MIT", "file": "LICENSE" },
  "assets": { "light": "light.css", "dark": "dark.css" },
  "files": [{ "path": "assets/logo.svg", "size": 12345, "type": "image/svg+xml" }],
  "inactive": { "html": ["index.html"], "js": ["theme.js"] }
}
```

## Delete behavior
- Delete always permitted, even if in use.
- Affected users fall back to `ui.theme.default`; if that theme was deleted, reset default to `slate`.
- Purge DB rows and disk files in a transaction + cleanup job on failure.
- Audit includes counts of users affected and files deleted.

## Rate limiting
- Import attempts limited to **5 per 10 minutes** per admin account.

---

# Branding Uploads

## Types and caps
- Allowed: `svg png jpg jpeg webp`
- Max size per file: **5 MB**
- MIME sniff required; reject mismatches.
- SVGs sanitized; remote refs stripped.

## Defaults
- If `ui.brand.favicon` is absent, derive from Primary logo.
- Footer logo uses Primary when Footer not set unless `ui.brand.footer_logo_disabled = true`.

---

# Feature Flags
- `THEME_CONFIG_ENABLED` (env → config)  
  - Default: **on** in development, **off** in production until QA sign-off.

---

# RBAC
- Global UI changes and theme imports require `role_admin` or permission `admin.theme`.
- Per-user preferences require authentication.

---

# Errors (selected)
- `VALIDATION_FAILED` — 422
- `THEME_IMPORT_INVALID` — 422
- `PAYLOAD_TOO_LARGE` — 413
- `UNSUPPORTED_MEDIA_TYPE` — 415
- `CONFLICT` — 409 (e.g., slug already exists)

---

# Examples

## PUT `/settings/ui` (partial)
```json
{
  "ui": {
    "theme": {
      "default": "slate",
      "allow_user_override": true,
      "force_global": false,
      "overrides": {
        "color.primary": "rgba(52,152,219,1)",
        "shadow": "light",
        "spacing": "wide",
        "typeScale": "medium",
        "motion": "limited"
      }
    },
    "nav": {
      "sidebar": { "default_order": ["risks","compliance","audits","policies"] }
    },
    "brand": {
      "title_text": "phpGRC — Dashboard"
    }
  }
}
```

## PUT `/me/prefs/ui`
```json
{
  "theme": "flatly",
  "mode": "dark",
  "overrides": { "spacing": "narrow" },
  "sidebar": { "collapsed": false, "width": 280, "order": ["risks","audits","compliance"] }
}
```

## POST `/settings/ui/themes/import` (multipart)
```
Content-Type: multipart/form-data; boundary=...
--... 
Content-Disposition: form-data; name="file"; filename="mytheme.zip"
Content-Type: application/zip

<binary zip bytes>
--...--
```

## Error example (422 VALIDATION_FAILED)
```json
{
  "ok": false,
  "code": "VALIDATION_FAILED",
  "errors": {
    "ui.theme.overrides.color.primary": ["Insufficient contrast against surface color"]
  }
}
```

---

# Appendix — Core metrics defaults (ops)

These defaults are used by the internal KPIs for Phase-5. They do **not** change the OpenAPI schema in 0.4.6.

- `CORE_METRICS_EVIDENCE_FRESHNESS_DAYS=30` → `config('core.metrics.evidence_freshness.days')`
- `CORE_METRICS_RBAC_DENIES_WINDOW_DAYS=7` → `config('core.metrics.rbac_denies.window_days')`

Client and server **clamp** user-supplied windows to `1..365`. Non-numeric values fall back to defaults.
