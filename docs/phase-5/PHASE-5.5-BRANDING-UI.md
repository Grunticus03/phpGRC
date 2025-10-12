# Phase 5.5 — Branding & Logos UI Design

Status: Draft — targeting THEME-004  
Owner: Web

---

## 1. Objectives
- Build an Admin UI for managing branding assets (primary/secondary/header/footer logos, favicon, title text).
- Provide upload/delete flows that comply with size/type/sanitization rules.
- Surface audit/restriction states and optimistic concurrency (ETag + `If-Match`).
- Handle fallback defaults (favicon derived from primary, footer fallback, disable flag).
- Prepare for later integration with theme pack manifest (logo references).

---

## 2. API Overview (Phase 5.5)
| Endpoint | Method | Notes |
|----------|--------|-------|
| `/api/settings/ui` | GET/PUT | Includes `ui.brand.*` values. Responses echo `etag` + config. |
| `/api/settings/ui/brand-assets` | GET | List of existing assets with metadata (id, type, mime, size, sha256, created_at). |
| `/api/settings/ui/brand-assets` | POST | Upload asset. Multipart fields: `kind`, `file`. Returns `{ ok, asset }`. |
| `/api/settings/ui/brand-assets/{id}` | DELETE | Remove asset. Should update settings when referenced asset removed. |
| `/api/settings/ui/brand-assets/{id}/preview` | GET | Optional signed URL for preview (future). |

### Asset Metadata
```ts
type BrandAsset = {
  id: string;                      // ULID
  kind: "primary_logo" | "secondary_logo" | "header_logo" | "footer_logo" | "favicon";
  name: string;                    // original filename
  mime: string;
  size_bytes: number;
  sha256: string;
  uploaded_by: string | null;      // user id or name
  created_at: string;              // ISO timestamp
  url?: string;                    // optional signed url for preview
};
```

### Branding Portion in Settings
```ts
type BrandingConfig = {
  title_text: string;
  favicon_asset_id: string | null;
  primary_logo_asset_id: string | null;
  secondary_logo_asset_id: string | null;
  header_logo_asset_id: string | null;
  footer_logo_asset_id: string | null;
  footer_logo_disabled: boolean;
};
```

---

## 3. UI Structure
- Extend Admin Settings page with `Branding` card (after Theme card).
- Sections within card:
  1. Title text input (with default hint `phpGRC — <module>`).
  2. Logo upload slots (primary, secondary, header, footer).
  3. Favicon slot (with note about fallback).
  4. Footer logo disable toggle.
  5. Asset list with metadata (size, type, uploaded by).
  6. Preview thumbnails (if available) or placeholder icon.
- Buttons:
  - `Upload` (per slot)
  - `Use Primary` (for secondary/header/footer to copy id)
  - `Remove` (clear reference to asset)
  - `Delete asset` (danger)
  - `Save branding` (apply references/title)
- Addresses concurrency via `If-Match` using same ETag as theme configurator.

---

## 4. Validation & UX Rules
- Allowed MIME: `image/svg+xml`, `image/png`, `image/jpeg`, `image/webp`. (SVG sanitized server-side.)
- Max file size: 5 MB. Show progress & failure messages.
- On upload success: update asset list & set corresponding config field.
- If asset deletion removes referenced id, clear config field and warn user.
- Provide textual fallback explanation (favicon from primary if blank & not disabled).
- Footer disable toggle: when set true, ignore footer asset on front-end.
- Title text: allow editing plain string, trimmed; enforce max length (e.g., 120 chars).
- Display audit hints (e.g., “Changes are audited as `ui.brand.updated`”).

---

## 5. API Interactions (Optimistic Concurrency)
- `GET /api/settings/ui` to prime branding config (with ETag).
- `GET /api/settings/ui/brand-assets` to populate list.
- `POST /api/settings/ui/brand-assets` per upload (multipart).
- `PUT /api/settings/ui` with payload:
```json
{
  "ui": {
    "brand": {
      "title_text": "phpGRC",
      "favicon_asset_id": "as_...",
      "primary_logo_asset_id": "as_...",
      "secondary_logo_asset_id": "as_...",
      "header_logo_asset_id": "as_...",
      "footer_logo_asset_id": "as_...",
      "footer_logo_disabled": false
    }
  }
}
```
- `DELETE /api/settings/ui/brand-assets/{id}` to remove asset (require confirmation).
- All write operations must use current ETag (409 -> reload assets + config).

---

## 6. Component Breakdown
| Component | Responsibility |
|-----------|----------------|
| `BrandingCard` | Main entry; handles fetch, state, concurrency, forms. |
| `BrandAssetUploader` | Encapsulates file input, validations, progress UI. |
| `AssetPreview` | Displays thumbnail or placeholder icon. |
| `AssetList` | Table of assets with delete buttons. |
| `BrandingContext` (optional) | Share config/etag across child components similar to ThemeConfigurator. |
| `useBrandAssets` hook | Manage fetch/upload/delete logic & messages. |

---

## 7. Testing Strategy
- Vitest component tests with mocked fetch:
  1. Happy path: load assets/config, upload logo, ensure config field updates.
  2. Upload failure (415/413) shows error message.
  3. Delete asset clears config when referenced.
  4. 409 response triggers reload.
  5. 403 read-only disables controls.
- Later Playwright: verify previews and theme interactions.

---

## 8. Future Enhancements
- Preview cropping & aspect ratio hints.
- Drag-and-drop upload.
- Batch upload support.
- Dedicated branding docs link from card.

---
