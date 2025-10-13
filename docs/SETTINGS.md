# phpGRC Settings Guide

Scope: **UI settings only**. Core Phase-4 Evidence uploads ignore MIME and size validation by policy.

Canonical runtime settings. Stored in DB unless noted. Avatars and theme pack files are on disk.

> **Phase sync note (additive):** As of Phase 5, **all non-connection Core settings** persist in DB (`core_settings`) and load at boot. This document remains focused on **UI** settings (Phase 5.5). UI settings must also persist in DB; do **not** place UI settings in `.env`.

## Conventions

- Keys use dot.case; request and response payloads follow the OpenAPI schemas in `docs/api/openapi.json`.
- `GET /settings/ui` returns `{ ok, config: { ui }, etag }` and always sets `Cache-Control: no-store, max-age=0` plus `Pragma: no-cache`. When `If-None-Match` matches (including `*`) the endpoint replies `304` with headers only.
- `PUT /settings/ui` requires `If-Match` (current etag or `*`). Success responses echo the latest `etag`, return the normalized `config.ui`, and include a `changes[]` diff. Stale etags receive `409 PRECONDITION_FAILED` with `current_etag`.
- All writes validate and return `422 VALIDATION_FAILED` on errors.
- Additive changes only; breaking changes require a major version.
- Successful UI settings changes emit domain/audit events; sensitive bytes are never logged (see “RBAC” and “Branding Uploads”).
- UI settings live in the DB—`.env` merely seeds boot defaults and must not house UI overrides.

> File-upload rules below apply to **UI features** (branding and theme packs), not Evidence uploads in Phase 4.

## Storage

- Global settings: DB table `ui_settings` (key → JSON value).
  - **Status:** Phase 5.5 design locked; to be implemented with `ui_settings` table and typed JSON values.
- Per-user prefs: DB table `user_ui_prefs`.
  - **Status:** Phase 5.5 design locked; persisted in `ui_settings` keyed by user id.
- Branding assets: DB `brand_assets` (metadata) + disk file.
  - **Status:** Phase 5.5 design locked; files stored in `ui_assets` with ULID references.
- Theme packs: DB `themes` (+ optional `theme_assets`) + files under `/public/themes/<slug>/`.
  - **Status:** Phase 5.5 design locked; packs stored in `ui_assets` and extracted under `/public/themes/<slug>/`.
- Avatars: disk only; metadata on the user record.
  - **Status:** unchanged; out of UI-5.5 scope except for display settings.

---

# UI Settings (Phase 5.5)

## Global keys (`/settings/ui`)

- `ui.theme.default` : string — Bootswatch slug. **Default:** `slate`.
- `ui.theme.mode` : `"light" | "dark"` — default render mode applied to `ui.theme.default`. **Default:** `dark`.
- `ui.theme.allow_user_override` : boolean — allow user theme choice. **Default:** `true`.
- `ui.theme.force_global` : boolean — force global theme for everyone; **still allows user light/dark** if theme supports both. **Default:** `false`.
- `ui.theme.overrides` : object<string,string> — token → value. Allowed tokens only; values validated.
- `ui.nav.sidebar.default_order` : string[] — canonical module keys for default sidebar order.
- `ui.brand.title_text` : string — **Default:** `phpGRC — <module>`
- `ui.brand.favicon_asset_id` : ulid|null — points to a record in `brand_assets`.
- `ui.brand.primary_logo_asset_id` : ulid|null
- `ui.brand.secondary_logo_asset_id` : ulid|null
- `ui.brand.header_logo_asset_id` : ulid|null
- `ui.brand.footer_logo_asset_id` : ulid|null
- `ui.brand.footer_logo_disabled` : boolean — hide footer logo in exports. **Default:** `false`.

## Bootswatch Variant Mapping

When a Bootswatch theme supports both modes, the UI toggle swaps between the presets below. Themes not listed remain single-mode and automatically disable the toggle.

| Theme (slug) | Light preset | Dark preset |
| --- | --- | --- |
| cerulean | cerulean | slate |
| cosmo | cosmo | cyborg |
| flatly | flatly | darkly |
| journal | journal | quartz |
| litera | litera | vapor |
| lumen | lumen | solar |
| united | united | superhero |

Dark-first themes (`slate`, `cyborg`, `darkly`, `quartz`, `vapor`, `solar`, `superhero`) use the reciprocal light preset shown above when users switch to light mode.

All remaining Bootswatch slugs (`lux`, `materia`, `minty`, `morph`, `pulse`, `sandstone`, `simplex`, `sketchy`, `spacelab`, `yeti`, `zephyr`) currently ship as light-only variants.

### File Ref shape (read-only)

Stored in `brand_assets`.

```json
{
  "id": "01HGBC4YCY2A8X80PDV1HV9ZQG",
  "kind": "primary_logo",
  "name": "logo.png",
  "mime": "image/png",
  "size_bytes": 128764,
  "sha256": "aab84cd0b7d86e0c5c2a17d8934f5afbd9fbe9f7a53cadb5f6b4f985d02268f6",
  "uploaded_by": "user:42",
  "created_at": "2024-10-05T19:07:26.000000Z"
}
```

`uploaded_by` contains the actor's display name when available; otherwise it falls back to `user:<id>`.

### Response envelopes & diffs

- `GET /settings/ui` → `200` with `{ ok: true, config: { ui }, etag }` or `304` (empty body) when `If-None-Match` matches, including the wildcard `*`.
- `PUT /settings/ui` → `200` with `{ ok: true, etag, config: { ui }, changes: [] }`. Each `changes[]` entry reports the dot-notated key, `old`/`new` snapshots, and the `action` (`upsert` when persisting, `delete` when reverting to defaults).
- `409 PRECONDITION_FAILED` → `{ ok: false, code: "PRECONDITION_FAILED", message, current_etag }` plus the latest `ETag` header so clients can retry with the correct version.

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
  "files": [
    { "path": "assets/logo.svg", "size": 12345, "type": "image/svg+xml" }
  ],
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

## API

- `GET /settings/ui/brand-assets` → lists uploads ordered newest-first (`{ ok: true, assets: BrandAsset[] }`).
- `POST /settings/ui/brand-assets` → multipart upload with `file` and optional `kind` (`primary_logo`, `secondary_logo`, `header_logo`, `footer_logo`, `favicon`). Returns `201` with `{ ok: true, asset }` on success.
- `DELETE /settings/ui/brand-assets/{asset}` → removes the asset and clears any `ui.brand.*_asset_id` references that point to it. Returns `{ ok: true }`; missing assets yield `{ ok: false, code: "NOT_FOUND" }`.
- Error envelopes:
  - `400 UPLOAD_FAILED` when the request omits the file.
  - `500 UPLOAD_FAILED` when the uploaded bytes cannot be read.
  - `413 PAYLOAD_TOO_LARGE` when the decoded bytes exceed 5 MB.
  - `415 UNSUPPORTED_MEDIA_TYPE` when MIME sniffing rejects the upload.

## Types and caps

- Allowed: `svg png jpg jpeg webp`
- Max size per file: **5 MB**
- MIME sniff required; reject mismatches.
- SVGs sanitized; remote refs stripped.

## Defaults

- If `ui.brand.favicon_asset_id` is absent, derive from Primary logo when possible.
- Footer logo uses the primary logo when `ui.brand.footer_logo_asset_id` is null unless `ui.brand.footer_logo_disabled = true`.

---

# Feature Flags

- `THEME_CONFIG_ENABLED` (env → config)
  - Default: **on** in development, **off** in production until QA sign-off.
  - **Note:** flag gates UI routes only; it does not affect Core settings persistence.

---

# RBAC

- Global UI changes and theme imports require `role_admin` or capability `admin.theme` (granted to `role_theme_manager`).
- Read-only endpoints allow `role_theme_auditor` via `ui.theme.view`. Per-user preferences require authentication.
- **Audit (additive):**
  - `ui.theme.updated`, `ui.theme.overrides.updated`, `ui.brand.updated`, `ui.nav.sidebar.saved`,  
    `ui.theme.pack.imported|deleted|enabled|disabled`
  - Never store raw bytes or secrets in audit meta; include counts and identifiers only.

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

- Headers: `If-Match: W/"ui:abc123"`

```json
{
  "ui": {
    "theme": {
      "default": "cosmo",
      "allow_user_override": false,
      "force_global": true,
      "overrides": {
        "color.primary": "rgba(52,152,219,1)",
        "shadow": "light",
        "spacing": "wide",
        "typeScale": "medium",
        "motion": "limited"
      }
    },
    "nav": {
      "sidebar": {
        "default_order": ["risks", "compliance", "audits", "policies"]
      }
    },
    "brand": {
      "title_text": "phpGRC",
      "footer_logo_asset_id": "01HGBC4YCY2A8X80PDV1HV9ZQG",
      "footer_logo_disabled": false
    }
  }
}
```

_Response (200) excerpt_

```json
{
  "ok": true,
  "etag": "W/\"ui:def456\"",
  "config": {
    "ui": {
      "theme": {
        "default": "cosmo",
        "allow_user_override": false,
        "force_global": true
      },
      "nav": {
        "sidebar": {
          "default_order": ["risks", "compliance", "audits", "policies"]
        }
      },
      "brand": {
        "title_text": "phpGRC",
        "footer_logo_asset_id": "01HGBC4YCY2A8X80PDV1HV9ZQG",
        "footer_logo_disabled": false
      }
    }
  },
  "changes": [
    {
      "key": "ui.theme.default",
      "old": "slate",
      "new": "cosmo",
      "action": "upsert"
    },
    {
      "key": "ui.brand.footer_logo_asset_id",
      "old": null,
      "new": "01HGBC4YCY2A8X80PDV1HV9ZQG",
      "action": "upsert"
    }
  ]
}
```

## PUT `/me/prefs/ui`

```json
{
  "theme": "flatly",
  "mode": "dark",
  "overrides": { "spacing": "narrow" },
  "sidebar": {
    "collapsed": false,
    "width": 280,
    "order": ["risks", "audits", "compliance"]
  }
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
    "ui.theme.overrides.color.primary": [
      "Insufficient contrast against surface color"
    ]
  }
}
```

---

## Operational note (additive): API rate limiting knobs (Core settings)

> Managed via Core settings in DB. ENV keys set **defaults** only.

- Keys:
  - `core.api.throttle.enabled` — boolean
  - `core.api.throttle.strategy` — `"ip" | "session" | "user"`
  - `core.api.throttle.window_seconds` — integer ≥ 1
  - `core.api.throttle.max_requests` — integer ≥ 1
- ENV defaults:
  - `CORE_API_THROTTLE_ENABLED`, `CORE_API_THROTTLE_STRATEGY`, `CORE_API_THROTTLE_WINDOW_SECONDS`, `CORE_API_THROTTLE_MAX_REQUESTS`
- Examples:
  - Disable globally for load tests:
    ```json
    { "core": { "api": { "throttle": { "enabled": false } } } }
    ```
  - Force session strategy with tighter window:
    ```json
    {
      "core": {
        "api": {
          "throttle": {
            "enabled": true,
            "strategy": "session",
            "window_seconds": 30,
            "max_requests": 20
          }
        }
      }
    }
    ```

---

## Out-of-scope items (kept for visibility)

- Evidence upload validation changes (Phase 4 policy) — **not part of UI settings**.
- Core metric windows and RBAC toggles — managed via Core settings in DB, documented elsewhere.
- **Deprecated:** `core.metrics.throttle.*` knobs and `MetricsThrottle` middleware (superseded by `GenericRateLimit`). Retained in docs for reference only; always treated as disabled.
