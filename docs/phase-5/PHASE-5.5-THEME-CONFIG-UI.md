# Phase 5.5 — Theme Configurator UI Design

Status: Draft — pending implementation  
Owner: Web (Step 5.5.3 scaffold, 5.5.4 wiring)

---

## 1. Goals
- Provide an Admin UI for global theming, branding, and tokens consistent with BACKLOG THEME-001
  through THEME-007.
- Support per-user UI preferences (theme selection, light/dark, overrides, sidebar layout).
- Align with the API contract delivered in `/settings/ui`, `/settings/ui/themes*`, `/me/prefs/ui`, and
  branding asset endpoints (Phase 5.5).
- Honor optimistic concurrency (weak ETag + `If-Match`) for write operations.
- Maintain accessibility requirements (WCAG 2.2 AA, prefers-reduced-motion).

---

## 2. Feature Scope for Step 5.5.3 (Scaffold)
- Scaffold Admin pages/cards for:
  - Theme selection (Bootswatch + imported packs).
  - Global token overrides (color, shadow, spacing, type scale, motion).
  - Branding assets summary (upload flows to follow).
  - Sidebar layout preview placeholder.
- Provide read-only state handling (RBAC denies should disable forms).
- Wire optimistic concurrency plumbing (`ETag` capture, `If-Match` submission, 409 retry/reload).
- Stub API interactions with mocked data pending backend delivery.
- Provide Vitest coverage verifying optimistic concurrency handling and basic state transitions.

---

## 3. Data Shapes
### 3.1 Theme Manifest (`GET /settings/ui/themes`)
```ts
type ThemeManifest = {
  version: string;                       // Bootswatch version, e.g., "5.3.3"
  defaults: { dark: string; light: string };
  themes: Array<{
    slug: string;                        // e.g., "slate"
    name: string;                        // human label
    source: "bootswatch" | "pack";
    supports: { mode: Array<"light"|"dark"> };
  }>;
  packs: Array<{
    slug: string;                        // namespaced: "pack:mytheme"
    name: string;
    author?: string;
    license?: { name: string; file: string };
  }>;
};
```

### 3.2 Global Settings (`GET /settings/ui`)
```ts
type UiSettingsResponse = {
  ok: boolean;
  etag: string;                          // matches response header
  config: {
    ui: {
      theme: {
        default: string;                 // theme slug
        allow_user_override: boolean;
        force_global: boolean;
        overrides: Record<string, string>;
      };
      nav: {
        sidebar: { default_order: string[] };
      };
      brand: {
        title_text: string;
        favicon_asset_id: string | null;
        primary_logo_asset_id: string | null;
        // other logos...
      };
    };
  };
};
```

### 3.3 Per-user Preferences (`GET /me/prefs/ui`)
```ts
type UiPrefsResponse = {
  ok: boolean;
  prefs: {
    theme: string | null;
    mode: "light" | "dark" | null;
    overrides: Record<string, string>;
    sidebar: { collapsed: boolean; width: number; order: string[] };
  };
  etag: string;
};
```

---

## 4. UI Architecture
### 4.1 Components
| Component | Responsibility |
|-----------|----------------|
| `ThemeConfigurator` | Core form for global theme+token settings (Admin route). |
| `ThemePreview` | Renders preview cards using selected theme overrides (Phase 5.5.4). |
| `BrandAssetsCard` | Lists existing branding assets with upload placeholders. |
| `SidebarLayoutCard` | Stub for customization flows (resizable preview placeholder). |
| `ThemeTokenEditor` | Shared editor for token categories (colors, shadow, spacing, etc). |
| `ThemeManifestContext` | Provides manifest + cached ETag to child components. |
| `UiSettingsContext` | Stores latest global settings, snapshot, dirty diff helpers. |
| `UiPrefsContext` | Provides user preference state (for later per-user route). |

### 4.2 Routing
- `/admin/settings/theme` (new nested route) to host the configurator cards.
- `/profile/preferences/theme` (future) for per-user overrides.
- Phase 5.5 scaffold: reuse existing `/admin/settings` with tabbed layout or new nested route.

### 4.3 State Management
- Use React context for theme manifest and settings snapshots to avoid prop drilling.
- Snapshot shape:
```ts
type GlobalThemeSnapshot = {
  etag: string | null;
  settings: UiSettingsResponse["config"]["ui"];
};
```
- Dirty diff detection: compare current form state versus `snapshot.settings` to build payload.
- Concurrency: `etagRef` in hook; POST/PATCH must include `If-Match`. 409 -> reload manifest/settings.

---

## 5. API Interactions

| Endpoint | Method | Notes |
|----------|--------|-------|
| `/api/admin/settings` | GET | Already implemented. Capture `ETag`, mutate read-only state when 403. |
| `/api/admin/settings` | POST | Use `If-Match`; handle 409 with reload. Continue to support stub responses. |
| `/api/settings/ui` | GET | Fetch global UI settings ( Phase 5.5 deliverable ). Provide ETag. |
| `/api/settings/ui` | PUT | Requires `If-Match`; returns new config + etag. |
| `/api/settings/ui/themes` | GET | Returns `ThemeManifest`. Cache in context. |
| `/api/settings/ui/themes/import` | POST | Zip upload (Phase 5.5 later). |
| `/api/settings/ui/brand-assets` | POST | Branding uploads (future step). |
| `/api/me/prefs/ui` | GET | Per-user preferences. ETag management identical. |
| `/api/me/prefs/ui` | PUT | Applies per-user overrides. |

Scaffold will mock `/settings/ui*` with temporary data until API lands; tests must assert payload
shape and `If-Match` emission.

---

## 6. Error Handling
- Display inline banner when:
  - Load fails: “Failed to load theme settings.”
  - Save fails (network/non-409): “Save failed.” (include message when available).
  - 409: “Settings changed in another session. Latest values have been reloaded.”
- Read-only mode (403 from GET): disable form inputs, show message.
- Tests should cover 200, 403, 409 flows.

---

## 7. Accessibility & UX Notes
- Use `<Tabs>` / nav pills to break sections: `Theme`, `Tokens`, `Branding`, `Sidebar`.
- Provide accessible labels and helper text for token editors.
- `ThemeTokenEditor`: range inputs must expose min/max; color inputs require contrast preview.
- Provide preview swatches with ARIA descriptions and role="img".
- For per-user prefs, ensure toggle components are keyboard accessible.

---

## 8. Testing Strategy
- Vitest:
  - Mock fetch to return manifest + settings + ETag.
  - Assert `If-Match` header for saves.
  - Verify 409 reload flow updates UI message and fetches new data.
  - Confirm read-only handling (403) disables controls.
- Component tests for `ThemeTokenEditor` interactions (color input, reset).
- Future Playwright: screenshot baseline for Slate/Flatly (Step 5.5.12).

---

## 9. Open Questions / TODO
- Final UX decision: tabs within `/admin/settings` vs dedicated route.
- Determine color picker library (native `<input type="color">` vs custom). Default to native for
  scaffold phase.
- Confirm naming convention for token keys (`color.primary`, `shadow`, etc) from backend validation.
- Decide whether to reuse existing Settings store vs new contexts (leaning toward new context to
  avoid coupling legacy settings fields).

---
