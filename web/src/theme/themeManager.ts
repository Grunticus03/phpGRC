import { baseHeaders } from "../lib/api";
import {
  DEFAULT_THEME_MANIFEST,
  DEFAULT_THEME_SETTINGS,
  DEFAULT_USER_PREFS,
  type ThemeManifest,
  type ThemeSettings,
  type ThemeUserPrefs,
  type CustomThemePack,
  type ThemeVariant,
} from "../routes/admin/themeData";
import { BOOTSWATCH_THEME_HREFS, BOOTSWATCH_THEMES, getBootswatchVariant, getBootswatchTheme } from "./bootswatch";

type ThemeMode = "light" | "dark";
type DesignerStorageMode = "browser" | "filesystem";
type LoginLayout = "layout_1" | "layout_2" | "layout_3";

type ManifestEntry = ThemeManifest["themes"][number] | ThemeManifest["packs"][number];

type ThemeVariantSelection = ThemeVariant & { mode: ThemeMode };
type WidenLiteral<T> = T extends string
  ? string
  : T extends number
  ? number
  : T extends boolean
  ? boolean
  : T;

type Mutable<T> = T extends (...args: infer A) => infer R
  ? (...args: A) => R
  : T extends object
  ? { -readonly [K in keyof T]: Mutable<WidenLiteral<T[K]>> }
  : WidenLiteral<T>;
type MutableThemeSettings = Mutable<ThemeSettings["theme"]>;
type MutableBrandSettings = Mutable<ThemeSettings["brand"]>;

type ThemeSelection = {
  slug: string;
  mode: ThemeMode;
  source: string;
  variant: ThemeVariantSelection | null;
};

type PublicUiSettings = {
  theme?: {
    default?: string | null;
    mode?: ThemeMode | null;
    allow_user_override?: boolean;
    force_global?: boolean;
    overrides?: Record<string, unknown>;
    login?: {
      layout?: string | null;
    };
  };
  brand?: {
    profile_id?: string | null;
    title_text?: string | null;
    favicon_asset_id?: string | null;
    primary_logo_asset_id?: string | null;
    secondary_logo_asset_id?: string | null;
    header_logo_asset_id?: string | null;
    footer_logo_asset_id?: string | null;
    background_login_asset_id?: string | null;
    background_main_asset_id?: string | null;
    footer_logo_disabled?: boolean | null;
  };
};

const THEME_LINK_ID = "phpgrc-theme-css";
const APP_FAVICON_LINK_ID = "phpgrc-favicon-link";
const BRAND_ASSET_BASE_PATH = "/api/settings/ui/brand-assets";
const CUSTOM_THEME_STORAGE_KEY = "phpgrc.customThemePacks";
const THEME_SELECTION_STORAGE_KEY = "phpgrc.theme.selection";
const THEME_SELECTION_COOKIE = "phpgrc_theme_selection";
const THEME_SELECTION_COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 30; // 30 days
const LOGIN_LAYOUT_STORAGE_KEY = "phpgrc.login.layout";
const LOGIN_LAYOUT_COOKIE = "phpgrc_login_layout";
const LOGIN_LAYOUT_COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 180; // ~6 months

const hasLocalStorage = (): boolean => {
  if (typeof window === "undefined") return false;
  try {
    return typeof window.localStorage !== "undefined" && window.localStorage !== null;
  } catch {
    return false;
  }
};

const isValidMode = (value: unknown): value is ThemeMode => value === "light" || value === "dark";

const sanitizeVariables = (input: unknown): Record<string, string> => {
  const result: Record<string, string> = {};
  if (input && typeof input === "object") {
    Object.entries(input as Record<string, unknown>).forEach(([key, value]) => {
      if (typeof key === "string" && typeof value === "string") {
        result[key] = value;
      }
    });
  }
  return result;
};

const sanitizeLoginLayout = (value: unknown): LoginLayout | null => {
  if (typeof value !== "string") return null;
  const token = value.trim().toLowerCase();
  return token === "layout_1" || token === "layout_2" || token === "layout_3"
    ? (token as LoginLayout)
    : null;
};

const getDatasetLoginLayout = (): LoginLayout | null => {
  if (typeof document === "undefined") return null;
  const attr = document.documentElement.getAttribute("data-login-layout");
  return sanitizeLoginLayout(attr);
};

const applyLoginLayoutDataset = (layout: LoginLayout | null): void => {
  if (typeof document === "undefined") return;
  if (layout) {
    document.documentElement.setAttribute("data-login-layout", layout);
  } else {
    document.documentElement.removeAttribute("data-login-layout");
  }
};

const persistLoginLayout = (layout: LoginLayout): void => {
  if (hasLocalStorage()) {
    try {
      window.localStorage.setItem(LOGIN_LAYOUT_STORAGE_KEY, layout);
    } catch {
      // ignore storage failures
    }
  }
  if (typeof document !== "undefined") {
    const attributes = [
      "path=/",
      `max-age=${LOGIN_LAYOUT_COOKIE_MAX_AGE_SECONDS}`,
      "SameSite=Lax",
    ];
    if (typeof window !== "undefined" && window.location?.protocol === "https:") {
      attributes.push("Secure");
    }
    document.cookie = `${LOGIN_LAYOUT_COOKIE}=${encodeURIComponent(layout)}; ${attributes.join("; ")}`;
  }

  applyLoginLayoutDataset(layout);
};

const loadStoredLoginLayout = (): LoginLayout | null => {
  if (hasLocalStorage()) {
    try {
      const raw = window.localStorage.getItem(LOGIN_LAYOUT_STORAGE_KEY);
      const stored = sanitizeLoginLayout(raw);
      if (stored) return stored;
    } catch {
      // ignore read failure and fall through to cookies
    }
  }

  if (typeof document !== "undefined" && typeof document.cookie === "string") {
    const entries = document.cookie.split(";");
    for (const entry of entries) {
      const trimmed = entry.trim();
      if (!trimmed) continue;
      if (trimmed.startsWith(`${LOGIN_LAYOUT_COOKIE}=`)) {
        const token = trimmed.substring(LOGIN_LAYOUT_COOKIE.length + 1);
        try {
          const decoded = decodeURIComponent(token);
          const stored = sanitizeLoginLayout(decoded);
          if (stored) return stored;
        } catch {
          const stored = sanitizeLoginLayout(token);
          if (stored) return stored;
        }
      }
    }
  }

  return null;
};

const initialDatasetLoginLayout = getDatasetLoginLayout();
const initialStoredLoginLayout = loadStoredLoginLayout();
const initialLoginLayout = initialDatasetLoginLayout ?? initialStoredLoginLayout;
if (initialDatasetLoginLayout && !initialStoredLoginLayout) {
  persistLoginLayout(initialDatasetLoginLayout);
}

const buildBrandAssetUrl = (assetId: string): string =>
  `${BRAND_ASSET_BASE_PATH}/${encodeURIComponent(assetId)}/download`;

export const getBrandAssetUrl = (assetId: string | null | undefined): string | null => {
  if (typeof assetId !== "string") {
    return null;
  }
  const trimmed = assetId.trim();
  if (trimmed === "") {
    return null;
  }

  const base = buildBrandAssetUrl(trimmed);
  return `${base}?v=${encodeURIComponent(trimmed)}`;
};

export const resolveBrandBackgroundUrl = (
  settings: ThemeSettings | null | undefined,
  kind: "login" | "main"
): string | null => {
  const brand = settings?.brand ?? DEFAULT_THEME_SETTINGS.brand;
  const brandData = brand as {
    background_login_asset_id?: string | null;
    background_main_asset_id?: string | null;
  };
  const login = getBrandAssetUrl(brandData.background_login_asset_id ?? null);
  const main = getBrandAssetUrl(brandData.background_main_asset_id ?? null);
  if (kind === "login") {
    return login ?? main;
  }

  return main;
};

const normalizeCustomPack = (input: Partial<CustomThemePack>): CustomThemePack | null => {
  const slug = typeof input.slug === "string" ? input.slug.trim() : "";
  const name = typeof input.name === "string" ? input.name.trim() : "";
  if (slug === "" || name === "") return null;
  const modeRaw = Array.isArray(input.supports?.mode) ? input.supports.mode : [];
  const modes = modeRaw.filter(isValidMode);
  const uniqueModes = Array.from(new Set(modes));
  const supports = { mode: (uniqueModes.length > 0 ? uniqueModes : ["light", "dark"]) as ThemeMode[] };
  const fallbackMode = supports.mode.includes("light") ? "light" : "dark";
  const defaultMode = isValidMode(input.default_mode) ? input.default_mode : fallbackMode;
  const variables = sanitizeVariables(input.variables);
  const variantsInput = input.variants;
  const variants: Partial<Record<ThemeMode, ThemeVariant>> = {};
  if (variantsInput && typeof variantsInput === "object") {
    (["light", "dark"] as const).forEach((mode) => {
      const candidate = (variantsInput as Partial<Record<ThemeMode, unknown>>)[mode];
      if (candidate && typeof candidate === "object") {
        const slugCandidate = (candidate as { slug?: unknown }).slug;
        const nameCandidate = (candidate as { name?: unknown }).name;
        const slug = typeof slugCandidate === "string" ? slugCandidate.trim() : "";
        const name = typeof nameCandidate === "string" ? nameCandidate.trim() : "";
        if (slug !== "" && name !== "") {
          variants[mode] = { slug, name };
        }
      }
    });
  }
  Object.keys(variants).forEach((mode) => {
    if (isValidMode(mode) && !supports.mode.includes(mode)) {
      supports.mode.push(mode);
    }
  });
  const pack: CustomThemePack = {
    slug,
    name,
    source: "custom",
    supports,
    default_mode: defaultMode,
    ...(Object.keys(variants).length > 0 ? { variants } : {}),
    ...(Object.keys(variables).length > 0 ? { variables } : {}),
  };
  if (input.locked === true) {
    pack.locked = true;
  }
  return pack;
};

const dedupeCustomPacks = (packs: Iterable<Partial<CustomThemePack>>): CustomThemePack[] => {
  const map = new Map<string, CustomThemePack>();
  for (const item of packs) {
    const normalized = normalizeCustomPack(item);
    if (normalized) {
      map.set(normalized.slug, normalized);
    }
  }
  return Array.from(map.values());
};

const mergeCustomPacks = (
  manifest: ThemeManifest,
  localCustom: Iterable<Partial<CustomThemePack>>
): ThemeManifest => {
  const map = new Map<string, CustomThemePack>();
  manifest.packs.forEach((pack) => {
    const normalized = normalizeCustomPack(pack);
    if (normalized) {
      map.set(normalized.slug, normalized);
    }
  });
  if (designerStorageMode === "browser") {
    for (const pack of localCustom) {
      const normalized = normalizeCustomPack(pack);
      if (normalized) {
        map.set(normalized.slug, normalized);
      }
    }
  }
  return {
    ...manifest,
    packs: Array.from(map.values()),
  };
};

const resolveDesignerStorage = (settings: ThemeSettings): DesignerStorageMode =>
  settings?.theme?.designer?.storage === "browser" ? "browser" : "filesystem";

const loadCustomThemePacks = (): CustomThemePack[] => {
  if (!hasLocalStorage()) return [];
  try {
    const raw = window.localStorage.getItem(CUSTOM_THEME_STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw) as unknown;
    if (!Array.isArray(parsed)) return [];
    return dedupeCustomPacks(parsed);
  } catch {
    return [];
  }
};

const clone = <T>(value: T): T => {
  if (typeof structuredClone === "function") {
    try {
      return structuredClone(value);
    } catch {
      // fall back to JSON
    }
  }
  return JSON.parse(JSON.stringify(value)) as T;
};

let settingsCache: ThemeSettings = clone(DEFAULT_THEME_SETTINGS);
if (initialLoginLayout) {
  settingsCache = {
    ...settingsCache,
    theme: {
      ...settingsCache.theme,
      login: {
        ...settingsCache.theme.login,
        layout: initialLoginLayout,
      },
    },
  };
  applyLoginLayoutDataset(initialLoginLayout);
}
let designerStorageMode: DesignerStorageMode = resolveDesignerStorage(settingsCache);
let customPackCache: CustomThemePack[] = designerStorageMode === "browser" ? loadCustomThemePacks() : [];

const persistCustomThemePacks = (): void => {
  if (designerStorageMode !== "browser") return;
  if (!hasLocalStorage()) return;
  try {
    window.localStorage.setItem(CUSTOM_THEME_STORAGE_KEY, JSON.stringify(customPackCache));
  } catch {
    // ignore storage errors (quota/security)
  }
};

const sanitizeNonEmptyString = (value: unknown): string | null => {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
};

const applyPublicUiSettings = (payload: PublicUiSettings): void => {
  const nextTheme: MutableThemeSettings = {
    ...settingsCache.theme,
    overrides: { ...(settingsCache.theme.overrides ?? {}) },
    login: { ...(settingsCache.theme.login ?? DEFAULT_THEME_SETTINGS.theme.login) },
  } as MutableThemeSettings;
  const nextBrand: MutableBrandSettings = {
    ...settingsCache.brand,
    assets: { ...(settingsCache.brand.assets ?? DEFAULT_THEME_SETTINGS.brand.assets) },
  } as MutableBrandSettings;
  let changed = false;

  if (payload.theme) {
    const theme = payload.theme;
    const defaultSlug = sanitizeNonEmptyString(theme.default);
    if (defaultSlug && nextTheme.default !== defaultSlug) {
      nextTheme.default = defaultSlug;
      changed = true;
    }

    if (theme.mode && isValidMode(theme.mode) && nextTheme.mode !== theme.mode) {
      nextTheme.mode = theme.mode;
      changed = true;
    }

    if (typeof theme.allow_user_override === "boolean" && nextTheme.allow_user_override !== theme.allow_user_override) {
      nextTheme.allow_user_override = theme.allow_user_override;
      changed = true;
    }

    if (typeof theme.force_global === "boolean" && nextTheme.force_global !== theme.force_global) {
      nextTheme.force_global = theme.force_global;
      changed = true;
    }

    if (theme.overrides && typeof theme.overrides === "object") {
      if (!nextTheme.overrides) {
        nextTheme.overrides = {} as MutableThemeSettings["overrides"];
      }
      let overridesChanged = false;
      Object.entries(theme.overrides).forEach(([key, value]) => {
        if (typeof key === "string" && typeof value === "string") {
          const current = (nextTheme.overrides as Record<string, string | undefined>)[key];
          if (current !== value) {
            (nextTheme.overrides as Record<string, string>)[key] = value;
            overridesChanged = true;
          }
        }
      });
      if (overridesChanged) {
        changed = true;
      }
    }

    const nextLayout = sanitizeLoginLayout(theme.login?.layout ?? null);
    if (nextLayout && nextTheme.login?.layout !== nextLayout) {
      nextTheme.login = { ...(nextTheme.login ?? DEFAULT_THEME_SETTINGS.theme.login), layout: nextLayout };
      persistLoginLayout(nextLayout);
      changed = true;
    }
  }

  if (payload.brand) {
    const brand = payload.brand;
    if (typeof brand.title_text === "string" && nextBrand.title_text !== brand.title_text) {
      nextBrand.title_text = brand.title_text;
      changed = true;
    }

    type BrandAssetField =
      | "favicon_asset_id"
      | "primary_logo_asset_id"
      | "secondary_logo_asset_id"
      | "header_logo_asset_id"
      | "footer_logo_asset_id"
      | "background_login_asset_id"
      | "background_main_asset_id";

    const updateBrandAsset = (field: BrandAssetField, value: unknown) => {
      if (value === null || typeof value === "string") {
        const normalized = (value ?? null) as MutableBrandSettings[typeof field];
        if (nextBrand[field] !== normalized) {
          nextBrand[field] = normalized;
          changed = true;
        }
      }
    };

    updateBrandAsset("favicon_asset_id", brand.favicon_asset_id);
    updateBrandAsset("primary_logo_asset_id", brand.primary_logo_asset_id);
    updateBrandAsset("secondary_logo_asset_id", brand.secondary_logo_asset_id);
    updateBrandAsset("header_logo_asset_id", brand.header_logo_asset_id);
    updateBrandAsset("footer_logo_asset_id", brand.footer_logo_asset_id);
    updateBrandAsset("background_login_asset_id", brand.background_login_asset_id);
    updateBrandAsset("background_main_asset_id", brand.background_main_asset_id);

    if (typeof brand.footer_logo_disabled === "boolean" && nextBrand.footer_logo_disabled !== brand.footer_logo_disabled) {
      nextBrand.footer_logo_disabled = brand.footer_logo_disabled;
      changed = true;
    }
  }

  if (!changed) {
    const layout = sanitizeLoginLayout(payload.theme?.login?.layout ?? null);
    if (layout) {
      applyLoginLayoutDataset(layout);
    }
    return;
  }

  settingsCache = {
    ...settingsCache,
    theme: nextTheme as ThemeSettings["theme"],
    brand: nextBrand as ThemeSettings["brand"],
  } as ThemeSettings;

  refreshTheme();
  notifySettingsListeners();
};

const persistSelectionSnapshot = (selection: ThemeSelection): void => {
  if (typeof document === "undefined") return;
  const snapshot = {
    slug: selection.slug,
    mode: selection.mode,
    source: selection.source,
    variant: selection.variant?.slug ?? null,
  };
  const payload = JSON.stringify(snapshot);

  if (hasLocalStorage()) {
    try {
      window.localStorage.setItem(THEME_SELECTION_STORAGE_KEY, payload);
    } catch {
      // ignore storage failures
    }
  }

  try {
    const encoded = encodeURIComponent(payload);
    document.cookie = [
      `${THEME_SELECTION_COOKIE}=${encoded}`,
      "Path=/",
      `Max-Age=${THEME_SELECTION_COOKIE_MAX_AGE_SECONDS}`,
      "SameSite=Lax",
    ].join("; ");
  } catch {
    // ignore cookie assignment failures
  }
};

let manifestCache: ThemeManifest = clone(mergeCustomPacks(DEFAULT_THEME_MANIFEST, customPackCache));
let prefsCache: ThemeUserPrefs = clone(DEFAULT_USER_PREFS);
let activeThemeVariables: Record<string, string> = {};
let appliedCustomVariableKeys: string[] = [];

let currentSelection: ThemeSelection | null = null;
let loadPromise: Promise<void> | null = null;

type ThemePrefsListener = (prefs: ThemeUserPrefs) => void;
type ThemeSettingsListener = (settings: ThemeSettings) => void;
type ThemeManifestListener = (manifest: ThemeManifest) => void;

const manifestListeners = new Set<ThemeManifestListener>();
const prefsListeners = new Set<ThemePrefsListener>();
const settingsListeners = new Set<ThemeSettingsListener>();

const notifyManifestListeners = (): void => {
  const snapshot = clone(manifestCache);
  manifestListeners.forEach((listener) => {
    try {
      listener(snapshot);
    } catch {
      // ignore listener errors
    }
  });
};

const notifyPrefsListeners = (): void => {
  const snapshot = clone(prefsCache);
  prefsListeners.forEach((listener) => {
    try {
      listener(snapshot);
    } catch {
      // ignore listener errors
    }
  });
};

const notifySettingsListeners = (): void => {
  const snapshot = clone(settingsCache);
  settingsListeners.forEach((listener) => {
    try {
      listener(snapshot);
    } catch {
      // ignore listener errors
    }
  });
};

const defaultModeForEntry = (entry: ManifestEntry | undefined): ThemeMode => {
  if (!entry) return "light";
  if ("default_mode" in entry) {
    const candidate = (entry as { default_mode?: ThemeMode }).default_mode;
    if (candidate === "light" || candidate === "dark") {
      return candidate;
    }
  }
  if ("default_mode" in (entry as CustomThemePack)) {
    const candidate = (entry as CustomThemePack).default_mode;
    if (candidate === "light" || candidate === "dark") {
      return candidate;
    }
  }
  if (entry.source === "bootswatch") {
    const meta = getBootswatchTheme(entry.slug);
    if (meta) {
      return meta.mode;
    }
  }
  return "light";
};

const variantForEntry = (entry: ManifestEntry | undefined, mode: ThemeMode): ThemeVariantSelection | null => {
  if (!entry) return null;
  const variants = (entry as { variants?: Partial<Record<ThemeMode, ThemeVariant>> }).variants;
  const variant = variants?.[mode];
  if (variant) {
    return { ...variant, mode };
  }

  if (entry.source === "bootswatch") {
    const fallback = getBootswatchVariant(entry.slug, mode);
    if (fallback) {
      return { slug: fallback.slug, name: fallback.name, mode };
    }
    const meta = getBootswatchTheme(entry.slug);
    if (meta && meta.mode === mode) {
      return { slug: meta.slug, name: meta.name, mode };
    }
  }

  if (entry.source === "custom") {
    const supportedModes = Array.isArray(entry.supports?.mode) ? entry.supports.mode : [];
    if (supportedModes.includes(mode)) {
      return { slug: entry.slug, name: entry.name, mode };
    }
  }

  return null;
};

const SHADOW_PRESETS: Record<string, string> = {
  none: "none",
  light: "0 .125rem .25rem rgba(0,0,0,.125)",
  default: "0 .5rem 1rem rgba(0,0,0,.15)",
  heavy: "0 1.25rem 3rem rgba(0,0,0,.35)",
};

const SPACING_SCALES: Record<string, string> = {
  narrow: "0.75rem",
  default: "1rem",
  wide: "1.5rem",
};

const TYPE_SCALES: Record<string, string> = {
  small: "0.95rem",
  medium: "1rem",
  large: "1.08rem",
};

const MOTION_PRESETS: Record<string, { duration: string; behavior: "auto" | "smooth" }> = {
  none: { duration: "0s", behavior: "auto" },
  limited: { duration: "0.12s", behavior: "smooth" },
  full: { duration: "0.2s", behavior: "smooth" },
};

const baseThemeOverrides = DEFAULT_THEME_SETTINGS.theme.overrides;
const baseUserOverrides = DEFAULT_USER_PREFS.overrides;

const shouldApplyOverride = (
  value: string | null | undefined,
  baseline: string | null | undefined
): string | null => {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  if (trimmed === "") return null;
  if (baseline !== undefined && baseline !== null && trimmed === baseline) {
    return null;
  }
  return trimmed;
};

const effectiveOverrides = (): ThemeSettings["theme"]["overrides"] => {
  const merged: Record<string, string> = {};

  const themeOverrides = settingsCache.theme.overrides ?? {};
  Object.entries(themeOverrides).forEach(([key, value]) => {
    const applied = shouldApplyOverride(value, baseThemeOverrides[key as keyof typeof baseThemeOverrides] ?? null);
    if (applied !== null) {
      merged[key] = applied;
    }
  });

  if (settingsCache.theme.allow_user_override && !settingsCache.theme.force_global) {
    const userOverrides = prefsCache.overrides ?? {};
    Object.entries(userOverrides).forEach(([key, value]) => {
      const applied = shouldApplyOverride(
        value,
        baseUserOverrides[key as keyof typeof baseUserOverrides] ??
          baseThemeOverrides[key as keyof typeof baseThemeOverrides] ??
          null
      );
      if (applied !== null) {
        merged[key] = applied;
      }
    });
  }

  return merged as ThemeSettings["theme"]["overrides"];
};

const applyDesignTokens = (): void => {
  if (typeof document === "undefined") return;
  const doc = document.documentElement;
  const body = document.body ?? document.documentElement;
  if (!doc) return;

  const overrides = effectiveOverrides();
  const setOrClear = (prop: string, value?: string) => {
    if (typeof value === "string" && value.trim() !== "") {
      doc.style.setProperty(prop, value);
    } else {
      doc.style.removeProperty(prop);
    }
  };

  const primary = overrides["color.primary"];
  setOrClear("--ui-color-primary", primary);
  setOrClear("--bs-primary", primary);
  setOrClear("--bs-link-color", primary);
  setOrClear("--bs-link-hover-color", primary);

  const background = overrides["color.background"];
  setOrClear("--ui-color-background", background);

  const surface = overrides["color.surface"];
  setOrClear("--ui-color-surface", surface);

  const backgroundCandidate =
    background && background.trim() !== "" ? background : surface && surface.trim() !== "" ? surface : null;

  if (backgroundCandidate && backgroundCandidate.trim() !== "") {
    setOrClear("--bs-body-bg", backgroundCandidate);
    body.style.backgroundColor = backgroundCandidate;
  } else {
    setOrClear("--bs-body-bg");
    body.style.removeProperty("background-color");
  }

  const text = overrides["color.text"];
  setOrClear("--ui-color-text", text);
  setOrClear("--bs-body-color", text);
  if (text && text.trim() !== "") {
    body.style.color = text;
  } else {
    body.style.removeProperty("color");
  }

  const shadowKey = overrides.shadow;
  if (shadowKey && shadowKey.trim() !== "") {
    const shadowValue = SHADOW_PRESETS[shadowKey] ?? shadowKey;
    setOrClear("--ui-shadow-surface", shadowValue);
    setOrClear("--bs-box-shadow", shadowValue);
  } else {
    setOrClear("--ui-shadow-surface");
    setOrClear("--bs-box-shadow");
  }

  const spacingKey = overrides.spacing;
  if (spacingKey && spacingKey.trim() !== "") {
    const spacingValue = SPACING_SCALES[spacingKey] ?? spacingKey;
    setOrClear("--ui-space-base", spacingValue);
    setOrClear("--bs-spacer", spacingValue);
  } else {
    setOrClear("--ui-space-base");
    setOrClear("--bs-spacer");
  }

  const typeKey = overrides.typeScale;
  if (typeKey && typeKey.trim() !== "") {
    const typeValue = TYPE_SCALES[typeKey] ?? typeKey;
    setOrClear("--ui-type-scale", typeKey);
    setOrClear("--bs-body-font-size", typeValue);
  } else {
    setOrClear("--ui-type-scale");
    setOrClear("--bs-body-font-size");
  }

  const motionKey = overrides.motion;
  if (motionKey && motionKey.trim() !== "") {
    const motion = MOTION_PRESETS[motionKey] ?? MOTION_PRESETS.full;
    setOrClear("--ui-motion-pref", motionKey);
    setOrClear("--bs-transition-duration", motion.duration);
    setOrClear("--bs-transition", `all ${motion.duration} ease-in-out`);
    doc.style.setProperty("scroll-behavior", motion.behavior);
  } else {
    setOrClear("--ui-motion-pref");
    setOrClear("--bs-transition-duration");
    setOrClear("--bs-transition");
    doc.style.removeProperty("scroll-behavior");
  }

  appliedCustomVariableKeys.forEach((variable) => {
    doc.style.removeProperty(variable);
  });
  appliedCustomVariableKeys = [];

  Object.entries(activeThemeVariables).forEach(([variable, value]) => {
    if (typeof value === "string" && value.trim() !== "") {
      doc.style.setProperty(variable, value);
      appliedCustomVariableKeys.push(variable);
    }
  });

  const mainBackgroundUrl = resolveBrandBackgroundUrl(settingsCache, "main");
  if (mainBackgroundUrl) {
    const cssUrl = `url("${mainBackgroundUrl}")`;
    doc.style.setProperty("--ui-app-background-image", cssUrl);
    body.style.backgroundImage = cssUrl;
    body.style.backgroundRepeat = "no-repeat";
    body.style.backgroundSize = "cover";
    body.style.backgroundPosition = "center center";
    body.style.backgroundAttachment = "fixed";
  } else {
    doc.style.removeProperty("--ui-app-background-image");
    body.style.removeProperty("background-image");
    body.style.removeProperty("background-repeat");
    body.style.removeProperty("background-size");
    body.style.removeProperty("background-position");
    body.style.removeProperty("background-attachment");
  }

  const loginBackgroundUrl = resolveBrandBackgroundUrl(settingsCache, "login");
  if (loginBackgroundUrl) {
    doc.style.setProperty("--ui-login-background-image", `url("${loginBackgroundUrl}")`);
  } else {
    doc.style.removeProperty("--ui-login-background-image");
  }

  const brand = settingsCache.brand ?? DEFAULT_THEME_SETTINGS.brand;
  const brandData = brand as {
    favicon_asset_id?: string | null;
    primary_logo_asset_id?: string | null;
  };
  const faviconCandidate =
    getBrandAssetUrl(brandData.favicon_asset_id ?? null) ??
    getBrandAssetUrl(brandData.primary_logo_asset_id ?? null) ??
    "/api/favicon.ico";
  updateFaviconLink(faviconCandidate);
};

const prefersDark = (): boolean => {
  if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
    return false;
  }
  return window.matchMedia("(prefers-color-scheme: dark)").matches;
};

const shouldAttachThemeStylesheet = (): boolean => {
  if (typeof navigator === "undefined" || typeof navigator.userAgent !== "string") {
    return true;
  }
  return !navigator.userAgent.toLowerCase().includes("jsdom");
};

const setThemeStylesheet = (href: string | null): void => {
  if (typeof document === "undefined" || !shouldAttachThemeStylesheet()) return;
  const head = document.head;
  const existing = document.getElementById(THEME_LINK_ID) as HTMLLinkElement | null;
  const pendingId = `${THEME_LINK_ID}-pending`;
  const pending = document.getElementById(pendingId) as HTMLLinkElement | null;

  if (!href) {
    pending?.remove();
    existing?.remove();
    return;
  }

  if (pending && pending.href === href) return;
  if (existing && existing.href === href) return;

  pending?.remove();

  const link = document.createElement("link");
  link.id = pendingId;
  link.rel = "stylesheet";
  link.type = "text/css";
  link.href = href;

  link.addEventListener("load", () => {
    const currentPending = document.getElementById(pendingId);
    if (currentPending !== link) {
      link.remove();
      return;
    }
    existing?.remove();
    link.id = THEME_LINK_ID;
  });

  link.addEventListener("error", () => {
    if (link.parentNode) {
      link.parentNode.removeChild(link);
    }
  }, { once: true });

  if (existing?.nextSibling) {
    existing.parentNode?.insertBefore(link, existing.nextSibling);
  } else {
    head.appendChild(link);
  }
};

const themeEntries = (manifest: ThemeManifest): ManifestEntry[] => [
  ...(manifest.themes ?? []),
  ...(manifest.packs ?? []),
];

const findManifestEntry = (manifest: ThemeManifest, slug: string): ManifestEntry | undefined => {
  const trimmed = slug?.trim();
  if (!trimmed) return undefined;
  return themeEntries(manifest).find((item) => item.slug === trimmed);
};

const sanitizeSlug = (slug: string | null | undefined): string | null => {
  if (typeof slug !== "string") return null;
  const trimmed = slug.trim();
  return trimmed.length > 0 ? trimmed : null;
};

const supportsModes = (entry: ManifestEntry | undefined): ThemeMode[] => {
  if (!entry?.supports?.mode) return [];
  const seen = new Set<ThemeMode>();
  const modes: ThemeMode[] = [];
  entry.supports.mode.forEach((value) => {
    if (!isValidMode(value)) return;
    if (seen.has(value)) return;
    if (variantForEntry(entry, value)) {
      seen.add(value);
      modes.push(value);
    }
  });
  return modes;
};

const resolveThemeSelection = (): ThemeSelection => {
  const manifest = manifestCache;
  const settings = settingsCache;
  const prefs = prefsCache;

  const manifestSlug =
    sanitizeSlug(settings.theme.default) ??
    sanitizeSlug(manifest.defaults?.dark ?? null) ??
    sanitizeSlug(manifest.defaults?.light ?? null) ??
    BOOTSWATCH_THEMES[0]?.slug ??
    "slate";

  let slug = manifestSlug;

  const canOverride = settings.theme.allow_user_override && !settings.theme.force_global;
  const preferredSlug = canOverride ? sanitizeSlug(prefs.theme) : null;
  if (preferredSlug) {
    const entry = findManifestEntry(manifest, preferredSlug);
    if (entry) {
      slug = preferredSlug;
    }
  }

  if (!findManifestEntry(manifest, slug)) {
    slug = manifestSlug;
  }

  if (!findManifestEntry(manifest, slug)) {
    slug = sanitizeSlug(manifest.defaults?.dark) ?? slug;
  }

  let entry = findManifestEntry(manifest, slug);
  let availableModes = supportsModes(entry);
  let mode: ThemeMode = defaultModeForEntry(entry);

  const getGlobalMode = (): ThemeMode | null => {
    const candidate = (settings.theme as { mode?: ThemeMode }).mode;
    return isValidMode(candidate) ? candidate : null;
  };

  const userModePref = canOverride ? (isValidMode(prefs.mode) ? prefs.mode : null) : null;
  const globalModePref = getGlobalMode();

  const desiredModes: ThemeMode[] = [];
  const pushMode = (candidate: ThemeMode | null | undefined) => {
    if (!candidate) return;
    if (!desiredModes.includes(candidate)) {
      desiredModes.push(candidate);
    }
  };

  pushMode(userModePref);
  if (!canOverride || settings.theme.force_global) {
    pushMode(globalModePref);
    pushMode(defaultModeForEntry(entry));
  } else {
    pushMode(globalModePref);
    pushMode(defaultModeForEntry(entry));
  }

  const systemPreferred = prefersDark() ? "dark" : "light";
  if (availableModes.includes(systemPreferred)) {
    pushMode(systemPreferred);
  }

  const resolveFromList = (candidates: ThemeMode[]): ThemeVariantSelection | null => {
    for (const candidate of candidates) {
      const resolved = variantForEntry(entry, candidate);
      if (resolved) {
        mode = resolved.mode;
        return resolved;
      }
    }
    return null;
  };

  let variant = resolveFromList(desiredModes);
  if (!variant) {
    variant = resolveFromList(availableModes);
  }
  if (!variant) {
    variant = variantForEntry(entry, defaultModeForEntry(entry));
    if (variant) {
      mode = variant.mode;
    }
  }

  if (!variant) {
    const fallbackSlug =
      sanitizeSlug(manifest.defaults?.dark ?? null) ??
      sanitizeSlug(manifest.defaults?.light ?? null) ??
      BOOTSWATCH_THEMES[0]?.slug ??
      "slate";
    slug = fallbackSlug;
    entry = findManifestEntry(manifest, slug);
    availableModes = supportsModes(entry);
    mode = defaultModeForEntry(entry);
    variant =
      resolveFromList([mode, prefersDark() ? "dark" : "light"]) ??
      variantForEntry(entry, mode) ??
      (entry
        ? { slug: entry.slug, name: entry.name, mode }
        : { slug: fallbackSlug, name: fallbackSlug, mode });
  }

  const source = entry?.source ?? "bootswatch";

  return { slug, mode, source, variant };
};

const findStylesheetHref = (slug: string): string | null => {
  const base = slug.includes(":") ? slug.split(":")[0] ?? slug : slug;
  if (base in BOOTSWATCH_THEME_HREFS) {
    return BOOTSWATCH_THEME_HREFS[base];
  }
  return null;
};

const applySelection = (selection: ThemeSelection): void => {
  if (typeof document === "undefined") return;

  const href = findStylesheetHref(selection.slug);
  setThemeStylesheet(href);

  const html = document.documentElement;
  html.setAttribute("data-theme", selection.slug);
  html.setAttribute("data-mode", selection.mode);
  html.setAttribute("data-bs-theme", selection.mode);
  html.setAttribute("data-theme-variant", selection.variant?.slug ?? selection.mode);
  html.setAttribute("data-theme-source", selection.source);

  currentSelection = selection;
  persistSelectionSnapshot(selection);
};

const refreshTheme = (): void => {
  const selection = resolveThemeSelection();
  if (
    !currentSelection ||
    currentSelection.slug !== selection.slug ||
    currentSelection.mode !== selection.mode ||
    currentSelection.source !== selection.source ||
    currentSelection.variant?.slug !== selection.variant?.slug
  ) {
    applySelection(selection);
  }
  const entry = findManifestEntry(manifestCache, selection.slug) as CustomThemePack | undefined;
  if (entry && entry.source === "custom" && entry.variables) {
    activeThemeVariables = { ...entry.variables };
  } else {
    activeThemeVariables = {};
  }
  applyDesignTokens();
};

const fetchJson = async <T>(url: string): Promise<T | null> => {
  try {
    const res = await fetch(url, {
      method: "GET",
      credentials: "same-origin",
      headers: baseHeaders(),
    });
    if (res.status === 401) {
      return null;
    }
    if (!res.ok) return null;
    const body = (await res.json()) as T;
    return body;
  } catch {
    return null;
  }
};

export const bootstrapTheme = async (options?: { fetchUserPrefs?: boolean }): Promise<void> => {
  if (loadPromise) {
    if (options?.fetchUserPrefs) {
      await loadPromise;
      return bootstrapTheme(options);
    }
    return loadPromise;
  }

  loadPromise = (async () => {
    const publicSettingsResponse = await fetchJson<{ config?: PublicUiSettings }>("/api/settings/ui/public");
    if (publicSettingsResponse?.config) {
      applyPublicUiSettings(publicSettingsResponse.config);
    }

    const manifest = await fetchJson<ThemeManifest>("/api/settings/ui/themes");
    if (manifest && Array.isArray(manifest.themes)) {
      manifestCache = clone(mergeCustomPacks(manifest, customPackCache));
      notifyManifestListeners();
    }

    const settingsResponse = await fetchJson<{ config?: { ui?: ThemeSettings } }>("/api/settings/ui");
    if (settingsResponse?.config?.ui) {
      settingsCache = settingsResponse.config.ui;
      const loginLayout = sanitizeLoginLayout(settingsCache.theme?.login?.layout ?? null);
      if (loginLayout) {
        persistLoginLayout(loginLayout);
      }
      designerStorageMode = resolveDesignerStorage(settingsCache);
      customPackCache = designerStorageMode === "browser" ? loadCustomThemePacks() : [];
      manifestCache = clone(mergeCustomPacks(manifestCache, customPackCache));
      notifySettingsListeners();
      notifyManifestListeners();
    }

    const shouldFetchPrefs =
      options?.fetchUserPrefs ?? (settingsCache.theme.allow_user_override && !settingsCache.theme.force_global);

    if (shouldFetchPrefs) {
      const prefsResponse = await fetchJson<{ prefs?: ThemeUserPrefs }>("/api/me/prefs/ui");
      if (prefsResponse?.prefs) {
        prefsCache = prefsResponse.prefs;
        notifyPrefsListeners();
      }
    }

    refreshTheme();
  })()
    .catch(() => {
      // ignore bootstrap errors; defaults already applied
    })
    .finally(() => {
      loadPromise = null;
    });

  return loadPromise;
};

export const updateThemeManifest = (manifest: ThemeManifest): void => {
  if (designerStorageMode === "browser") {
    const incomingCustomPacks = manifest.packs.filter((pack) => pack.source === "custom");
    if (incomingCustomPacks.length > 0) {
      customPackCache = dedupeCustomPacks([...customPackCache, ...incomingCustomPacks]);
      persistCustomThemePacks();
    }
  }
  manifestCache = clone(mergeCustomPacks(manifest, customPackCache));
  refreshTheme();
  notifyManifestListeners();
};

export const updateThemeSettings = (settings: ThemeSettings): void => {
  settingsCache = clone(settings);
  const loginLayout = sanitizeLoginLayout(settingsCache.theme?.login?.layout ?? null);
  if (loginLayout) {
    persistLoginLayout(loginLayout);
  }
  designerStorageMode = resolveDesignerStorage(settingsCache);
  customPackCache = designerStorageMode === "browser" ? loadCustomThemePacks() : [];
  manifestCache = clone(mergeCustomPacks(manifestCache, customPackCache));
  refreshTheme();
  notifySettingsListeners();
  notifyManifestListeners();
};

export const updateThemePrefs = (prefs: ThemeUserPrefs): void => {
  prefsCache = clone(prefs);
  refreshTheme();
  notifyPrefsListeners();
};

export const getStoredLoginLayout = (): LoginLayout | null => loadStoredLoginLayout();

const updateFaviconLink = (href: string | null): void => {
  if (typeof document === "undefined") return;
  const head = document.head;
  if (!head) return;

  const existing = document.getElementById(APP_FAVICON_LINK_ID) as HTMLLinkElement | null;
  if (!href) {
    existing?.remove();
    return;
  }

  const absoluteHref =
    typeof window !== "undefined" ? new URL(href, window.location.origin).href : href;

  const link = existing ?? (() => {
    const el = document.createElement("link");
    el.id = APP_FAVICON_LINK_ID;
    el.rel = "icon";
    el.type = "image/x-icon";
    head.appendChild(el);
    return el;
  })();

  if (link.href !== absoluteHref) {
    link.href = absoluteHref;
  }
};

export const getCurrentTheme = (): ThemeSelection => {
  if (currentSelection) return currentSelection;
  const selection = resolveThemeSelection();
  currentSelection = selection;
  applySelection(selection);
  applyDesignTokens();
  return selection;
};

export const getCachedThemeSettings = (): ThemeSettings => clone(settingsCache);

export const getCachedThemePrefs = (): ThemeUserPrefs => clone(prefsCache);

export const getCachedThemeManifest = (): ThemeManifest => clone(manifestCache);

export const onThemePrefsChange = (listener: ThemePrefsListener): (() => void) => {
  prefsListeners.add(listener);
  try {
    listener(clone(prefsCache));
  } catch {
    // ignore listener errors
  }
  return () => {
    prefsListeners.delete(listener);
  };
};

export const onThemeSettingsChange = (listener: ThemeSettingsListener): (() => void) => {
  settingsListeners.add(listener);
  try {
    listener(clone(settingsCache));
  } catch {
    // ignore listener errors
  }
  return () => {
    settingsListeners.delete(listener);
  };
};

export const onThemeManifestChange = (listener: ThemeManifestListener): (() => void) => {
  manifestListeners.add(listener);
  try {
    listener(clone(manifestCache));
  } catch {
    // ignore listener errors
  }
  return () => {
    manifestListeners.delete(listener);
  };
};

export const toggleThemeMode = (): ThemeMode => {
  const selection = resolveThemeSelection();
  const entry = findManifestEntry(manifestCache, selection.slug);
  const desiredMode: ThemeMode = selection.mode === "dark" ? "light" : "dark";

  if (!variantForEntry(entry, desiredMode)) {
    return selection.mode;
  }

  const allowOverride = settingsCache.theme.allow_user_override && !settingsCache.theme.force_global;

  if (allowOverride) {
    const nextPrefs: ThemeUserPrefs = {
      ...prefsCache,
      theme: prefsCache.theme,
      mode: desiredMode,
      overrides: { ...prefsCache.overrides },
      sidebar: {
        ...prefsCache.sidebar,
        order: [...prefsCache.sidebar.order],
      },
    };
    updateThemePrefs(nextPrefs);
    return desiredMode;
  }

  const nextSettings: ThemeSettings = {
    ...settingsCache,
    theme: {
      ...settingsCache.theme,
      default: selection.slug,
      mode: desiredMode,
      overrides: { ...settingsCache.theme.overrides },
      designer: {
        ...settingsCache.theme.designer,
      },
    },
  };
  updateThemeSettings(nextSettings);
  return desiredMode;
};

// Apply default theme immediately when running in a browser to reduce FOUC.
if (typeof document !== "undefined") {
  getCurrentTheme();
}
