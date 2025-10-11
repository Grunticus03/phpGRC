export const DEFAULT_THEME_MANIFEST = {
  version: "5.3.3",
  defaults: { dark: "slate", light: "flatly" },
  themes: [
    { slug: "slate", name: "Slate", source: "bootswatch", supports: { mode: ["dark"] } },
    { slug: "flatly", name: "Flatly", source: "bootswatch", supports: { mode: ["light"] } },
    { slug: "darkly", name: "Darkly", source: "bootswatch", supports: { mode: ["dark"] } },
    { slug: "cosmo", name: "Cosmo", source: "bootswatch", supports: { mode: ["light"] } },
  ],
  packs: [],
} as const;

export const DEFAULT_THEME_SETTINGS = {
  theme: {
    default: DEFAULT_THEME_MANIFEST.defaults.dark,
    allow_user_override: true,
    force_global: false,
    overrides: {
      "color.primary": "#0d6efd",
      "color.surface": "#1b1e21",
      "color.text": "#f8f9fa",
      shadow: "default",
      spacing: "default",
      typeScale: "medium",
      motion: "full",
    },
  },
  nav: {
    sidebar: {
      default_order: [],
    },
  },
  brand: {
    title_text: "phpGRC — Dashboard",
    favicon_asset_id: null,
    primary_logo_asset_id: null,
    secondary_logo_asset_id: null,
    header_logo_asset_id: null,
    footer_logo_asset_id: null,
    footer_logo_disabled: false,
  },
} as const;

export const DEFAULT_USER_PREFS = {
  theme: null,
  mode: null as "light" | "dark" | null,
  overrides: {
    "color.primary": "#0d6efd",
    "color.surface": "#1b1e21",
    "color.text": "#f8f9fa",
    shadow: "default",
    spacing: "default",
    typeScale: "medium",
    motion: "full",
  },
  sidebar: {
    collapsed: false,
    width: 280,
    order: [] as string[],
  },
} as const;

export type ThemeManifest = typeof DEFAULT_THEME_MANIFEST;
export type ThemeSettings = typeof DEFAULT_THEME_SETTINGS;
export type ThemeUserPrefs = typeof DEFAULT_USER_PREFS;
