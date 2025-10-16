import { BOOTSWATCH_THEMES, BOOTSWATCH_THEME_VARIANTS } from "../../theme/bootswatch";

export type ThemeMode = "light" | "dark";

export type ThemeVariant = {
  slug: string;
  name: string;
};

export type ThemeManifestTheme = {
  slug: string;
  name: string;
  source: "bootswatch";
  default_mode: ThemeMode;
  supports: { mode: ThemeMode[] };
  variants?: Partial<Record<ThemeMode, ThemeVariant>>;
};

export type CustomThemePack = {
  slug: string;
  name: string;
  source: "custom";
  supports: { mode: ThemeMode[] };
  default_mode?: ThemeMode;
  variants?: Partial<Record<ThemeMode, ThemeVariant>>;
  variables?: Record<string, string>;
};

export type ThemeManifest = {
  version: string;
  defaults: { dark: string; light: string };
  themes: ThemeManifestTheme[];
  packs: CustomThemePack[];
};

export const DEFAULT_THEME_MANIFEST: ThemeManifest = {
  version: "5.3.8",
  defaults: { dark: "slate", light: "flatly" },
  themes: BOOTSWATCH_THEMES.map((theme) => {
    const variantsMeta = BOOTSWATCH_THEME_VARIANTS[theme.slug] ?? {};
    const variants: Partial<Record<ThemeMode, ThemeVariant>> = {};
    const modeSet = new Set<ThemeMode>([theme.mode]);

    (Object.entries(variantsMeta) as Array<[ThemeMode, typeof variantsMeta[keyof typeof variantsMeta]]>).forEach(
      ([mode, meta]) => {
        if (!meta) return;
        modeSet.add(mode);
        variants[mode] = { slug: meta.slug, name: meta.name };
      }
    );

    return {
      slug: theme.slug,
      name: theme.name,
      source: "bootswatch",
      default_mode: theme.mode as ThemeMode,
      supports: { mode: Array.from(modeSet) as ThemeMode[] },
      ...(Object.keys(variants).length > 0 ? { variants } : {}),
    };
  }),
  packs: [],
};

export const DEFAULT_THEME_SETTINGS = {
  theme: {
    designer: {
      storage: "filesystem" as "filesystem" | "browser",
      filesystem_path: "/opt/phpgrc/shared/themes" as string,
    },
    default: DEFAULT_THEME_MANIFEST.defaults.dark,
    mode: "dark" as ThemeMode,
    allow_user_override: true,
    force_global: false,
    overrides: {
      "color.primary": "#0d6efd",
      "color.background": "#10131a",
      "color.surface": "#1b1e21",
      "color.text": "#f8f9fa",
      shadow: "default",
      spacing: "default",
      typeScale: "medium",
      motion: "full",
    },
    login: {
      layout: "layout_1" as "layout_1" | "layout_2" | "layout_3",
    },
  },
  nav: {
    sidebar: {
      default_order: [],
    },
  },
  brand: {
    title_text: "phpGRC",
    favicon_asset_id: null,
    primary_logo_asset_id: null,
    secondary_logo_asset_id: null,
    header_logo_asset_id: null,
    footer_logo_asset_id: null,
    footer_logo_disabled: false,
    assets: {
      filesystem_path: "/opt/phpgrc/shared/brands" as string,
    },
  },
} as const;

export const DEFAULT_USER_PREFS = {
  theme: null as string | null,
  mode: null as "light" | "dark" | null,
  overrides: {
    "color.primary": "#0d6efd",
    "color.background": "#10131a",
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
    pinned: true,
    order: [] as string[],
    hidden: [] as string[],
  },
} as const;

export type ThemeSettings = typeof DEFAULT_THEME_SETTINGS;
export type ThemeUserPrefs = typeof DEFAULT_USER_PREFS;
