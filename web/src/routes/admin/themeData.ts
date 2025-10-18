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
  locked?: boolean;
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
  packs: [
    {
      slug: "glassmorphism-codex",
      name: "Glassmorphism (Codex)",
      source: "custom",
      default_mode: "light",
      supports: { mode: ["light", "dark"] },
      variants: {
        light: { slug: "glassmorphism-codex", name: "Glassmorphism — Primary" },
        dark: { slug: "glassmorphism-codex-dark", name: "Glassmorphism — Dark" },
      },
      variables: {
        "--codex-glass-backdrop": "blur(28px)",
        "--codex-glass-border": "1px solid rgba(255, 255, 255, 0.22)",
        "--codex-glass-highlight": "rgba(255, 255, 255, 0.35)",
        "--codex-glass-surface": "rgba(255, 255, 255, 0.55)",
        "--codex-glass-surface-dark": "rgba(15, 23, 42, 0.6)",
        "--codex-glass-shadow": "0 32px 80px rgba(15, 23, 42, 0.45)",
        "--codex-glass-text-light": "rgba(30, 41, 59, 0.9)",
        "--codex-glass-text-dark": "rgba(241, 245, 255, 0.92)",
        "--codex-glass-accent": "#60a5fa",
        "--codex-glass-accent-dark": "#38bdf8",
      },
      locked: true,
    },
    {
      slug: "animated-codex",
      name: "Animated (Codex)",
      source: "custom",
      default_mode: "light",
      supports: { mode: ["light", "dark"] },
      variants: {
        light: { slug: "animated-codex", name: "Animated — Primary" },
        dark: { slug: "animated-codex-dark", name: "Animated — Dark" },
      },
      variables: {
        "--codex-animated-gradient":
          "linear-gradient(120deg, rgba(59,130,246,0.9) 0%, rgba(236,72,153,0.85) 50%, rgba(16,185,129,0.85) 100%)",
        "--codex-animated-gradient-dark":
          "linear-gradient(130deg, rgba(59,130,246,0.85) 0%, rgba(147,51,234,0.85) 45%, rgba(14,116,144,0.9) 100%)",
        "--codex-animated-highlight": "#fbbf24",
        "--codex-animated-accent": "#f97316",
        "--codex-animated-accent-dark": "#fb923c",
        "--codex-animated-shadow": "0 28px 68px rgba(88, 28, 135, 0.45)",
        "--codex-animated-speed": "18s",
      },
      locked: true,
    },
  ],
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
    background_login_asset_id: null,
    background_main_asset_id: null,
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
  dashboard: {
    widgets: [] as Array<{
      id: string | null;
      type: string;
      x: number;
      y: number;
      w: number;
      h: number;
    }>,
  },
} as const;

export type ThemeSettings = typeof DEFAULT_THEME_SETTINGS;
export type ThemeUserPrefs = typeof DEFAULT_USER_PREFS;
