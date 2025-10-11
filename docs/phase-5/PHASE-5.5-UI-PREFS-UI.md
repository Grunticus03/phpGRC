# Phase 5.5 — Per-user UI Preferences UI Design

Status: Draft — pending implementation (THEME-003)  
Owner: Web

---

## 1. Objectives
- Provide an authenticated user-facing page allowing theme selection, light/dark mode choice, token overrides, and sidebar layout preferences.
- Mirror the API contract for `/me/prefs/ui` with optimistic concurrency (`If-Match`) and validation feedback.
- Ensure UI respects global constraints (force-global, allowed override tokens, available themes/modes).
- Deliver foundation for later richer customization (sidebar drag-order, design token preview).

---

## 2. Requirements Summary (from BACKLOG / STYLEGUIDE)
- Users may choose any theme when `ui.theme.allow_user_override = true`. When `force_global = true`, theme select is disabled but light/dark toggle remains available if theme supports both modes.
- Allowed override tokens identical to admin configurator (`color.*`, `shadow`, `spacing`, `typeScale`, `motion`), constrained to whitelist.
- Sidebar preferences: collapsed flag, width (50px–50% viewport), and module order array.
- LDAP or read-only roles must see a disabled page (401/403 behavior consistent with other profile screens).
- Optimistic concurrency: capture ETag from GET `/me/prefs/ui`, send in `If-Match`, reload on 409.
- Provide clear success, no-change, conflict, and validation messages.

---

## 3. Data Shapes
```ts
type UiPrefsResponse = {
  ok: boolean;
  etag: string;                // weak ETag, matches header
  prefs: {
    theme: string | null;
    mode: "light" | "dark" | null;
    overrides: Record<string, string>;
    sidebar: { collapsed: boolean; width: number; order: string[] };
  };
};

type UiPrefsPayload = {
  theme?: string | null;
  mode?: "light" | "dark" | null;
  overrides?: Record<string, string>;
  sidebar?: { collapsed: boolean; width: number; order: string[] };
};
```

Global settings will be needed to determine allowances:
```ts
type GlobalThemeSettings = {
  theme: {
    default: string;
    allow_user_override: boolean;
    force_global: boolean;
    overrides: Record<string, string>;
  };
};
```

---

## 4. UI Architecture
- New route: `/profile/preferences/theme` (component `ThemePreferences.tsx`).
- Layout: reuse `ProfileLayout`; card structure similar to Admin theme card but tailored to user context.
- Sections:
  1. Theme selection (dropdown) + mode toggle (radio buttons).
  2. Token overrides (`ThemeTokenEditor` reused).
  3. Sidebar preferences (collapsed switch, width slider, order list placeholder).
  4. Buttons: Reset to defaults (global effective), Reset to admin baseline (global theme overrides), Save.
- Messaging: inline `<div role="status" class="alert ...">`.
- Read-only (400/403): disable all inputs, show message.

---

## 5. API Interactions
| Endpoint | Method | Notes |
|----------|--------|-------|
| `/api/settings/ui` | GET | used to determine availability (allow override / force global). Cache ETag not needed. |
| `/api/me/prefs/ui` | GET | capture weak ETag, load prefs. |
| `/api/me/prefs/ui` | PUT | require `If-Match`, send diff payload. 409 triggers reload; 422 surface validation errors. |

Scaffold (Step 5.5.3/5.5.4) will mock responses until backend ready:
- For now, fallback to defaults when GET fails (and show warning).

---

## 6. State & Hooks
- `useThemePrefs` hook maintaining:
  - `globalSettings` (from admin endpoint).
  - `manifest` (reuse context from admin by fetching `/settings/ui/themes` or pass via props).
  - `prefs` form state (theme, mode, overrides, sidebar).
  - `etag`, `loading`, `saving`, `message`.
- `hasChanges(form, snapshot)` helper to detect diff (similar to admin).
- `resetToGlobal()` (set to admin defaults) vs `resetToBaseline()` (per-user last saved).

---

## 7. Validation Rules
- Theme select disabled when global `force_global` true; automatically set to global default.
- Mode toggle disabled when selected theme supports only one mode (from manifest).
- Overrides only for whitelisted keys (`color.*`, `shadow`, `spacing`, `typeScale`, `motion`). Disallow empty value.
- Sidebar width slider clamped 50px–50% (store as number of pixels; convert on UI).
- On 422, display errors adjacent to fields (structure to follow API response once available).

---

## 8. Testing Strategy
- Vitest component tests (mock fetch):
  1. Happy path: loads prefs + global settings, user changes theme/mode/overrides, triggers PUT with `If-Match`, message "Preferences saved."
  2. Force-global scenario: theme select disabled, mode available depending on manifest support.
  3. 409 conflict: first PUT returns 409 with `current_etag`, component reloads, message indicates reload.
  4. 403 on GET: inputs disabled, message shown.
- Extend `ThemeConfigurator` token editor tests if reused components share logic.
- Later Playwright scenario: user toggles theme and sees preview.

---

## 9. Roadmap / Dependencies
- Depends on manifest context (share with Admin page or fetch separately).
- Per-user page may initially live under `/profile` with route guard requiring auth.
- Branding uploads integration (THEME-004/006) not required for per-user page.

---

## 10. Open Questions
- Will `/me/prefs/ui` allow partial updates? (Assume yes; send diff similar to admin.)
- Should side panel order be editable in this step or placeholder? (For scaffold, placeholder text or simple reorder control with TODO.)
- How to handle validation error mapping once API defined? (Need to confirm keys with backend.)

---
