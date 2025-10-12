import { BOOTSWATCH_THEMES } from "../../theme/bootswatch";

export type ThemeMode = "light" | "dark";

export type ThemeManifestTheme = {
  slug: string;
  name: string;
  source: "bootswatch";
  supports: { mode: ThemeMode[] };
};

export type CustomThemePack = {
  slug: string;
  name: string;
  source: "custom";
  supports: { mode: ThemeMode[] };
  variables?: Record<string, string>;
};

export type ThemeManifest = {
  version: string;
  defaults: { dark: string; light: string };
  themes: ThemeManifestTheme[];
  packs: CustomThemePack[];
};

export const DEFAULT_THEME_MANIFEST: ThemeManifest = {
  version: "5.3.3",
  defaults: { dark: "slate", light: "flatly" },
  themes: BOOTSWATCH_THEMES.map((theme) => ({
    slug: theme.slug,
    name: theme.name,
    source: "bootswatch",
    supports: { mode: [theme.mode] as ThemeMode[] },
  })),
  packs: [],
};

export const DEFAULT_THEME_SETTINGS = {
  theme: {
    designer: {
      storage: "filesystem" as "filesystem" | "browser",
      filesystem_path: "/opt/phpgrc/shared/themes" as string,
    },
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

export type ThemeSettings = typeof DEFAULT_THEME_SETTINGS;
export type ThemeUserPrefs = typeof DEFAULT_USER_PREFS;
