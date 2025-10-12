import { baseHeaders } from "../lib/api";
import {
  DEFAULT_THEME_MANIFEST,
  DEFAULT_THEME_SETTINGS,
  DEFAULT_USER_PREFS,
  type ThemeManifest,
  type ThemeSettings,
  type ThemeUserPrefs,
} from "../routes/admin/themeData";
import { BOOTSWATCH_THEME_HREFS, BOOTSWATCH_THEMES } from "./bootswatch";

type ThemeMode = "light" | "dark";

type ManifestEntry = ThemeManifest["themes"][number] | ThemeManifest["packs"][number];

type ThemeSelection = {
  slug: string;
  mode: ThemeMode;
  source: string;
};

const THEME_LINK_ID = "phpgrc-theme-css";

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

let manifestCache: ThemeManifest = clone(DEFAULT_THEME_MANIFEST);
let settingsCache: ThemeSettings = clone(DEFAULT_THEME_SETTINGS);
let prefsCache: ThemeUserPrefs = clone(DEFAULT_USER_PREFS);

let currentSelection: ThemeSelection | null = null;
let loadPromise: Promise<void> | null = null;

type ThemePrefsListener = (prefs: ThemeUserPrefs) => void;
type ThemeSettingsListener = (settings: ThemeSettings) => void;

const prefsListeners = new Set<ThemePrefsListener>();
const settingsListeners = new Set<ThemeSettingsListener>();

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

const baseDefaultOverrides = DEFAULT_THEME_SETTINGS.theme.overrides;

const applyCustomOverrides = (
  source: Record<string, string | null | undefined>,
  target: Record<string, string>
): void => {
  Object.entries(source).forEach(([key, value]) => {
    if (typeof value !== "string") return;
    const trimmed = value.trim();
    if (trimmed === "") return;
    const defaultValue = baseDefaultOverrides[key as keyof typeof baseDefaultOverrides] ?? null;
    if (trimmed === defaultValue) return;
    target[key] = trimmed;
  });
};

const effectiveOverrides = (): ThemeSettings["theme"]["overrides"] => {
  const merged: Record<string, string> = {};

  applyCustomOverrides(settingsCache.theme.overrides ?? {}, merged);

  if (settingsCache.theme.allow_user_override && !settingsCache.theme.force_global) {
    applyCustomOverrides(prefsCache.overrides ?? {}, merged);
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

  const surface = overrides["color.surface"];
  setOrClear("--ui-color-surface", surface);
  setOrClear("--bs-body-bg", surface);
  if (surface && surface.trim() !== "") {
    body.style.backgroundColor = surface;
  } else {
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
};

const prefersDark = (): boolean => {
  if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
    return false;
  }
  return window.matchMedia("(prefers-color-scheme: dark)").matches;
};

const ensureThemeLink = (): HTMLLinkElement | null => {
  if (typeof document === "undefined") return null;
  let link = document.getElementById(THEME_LINK_ID) as HTMLLinkElement | null;
  if (!link) {
    link = document.createElement("link");
    link.id = THEME_LINK_ID;
    link.rel = "stylesheet";
    link.type = "text/css";
    document.head.appendChild(link);
  }
  return link;
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
  return entry.supports.mode.filter((mode): mode is ThemeMode => mode === "light" || mode === "dark");
};

const bootFallbackMode = (slug: string): ThemeMode => {
  const meta = BOOTSWATCH_THEMES.find((item) => item.slug === slug);
  return meta?.mode ?? "light";
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

  const entry = findManifestEntry(manifest, slug);
  const availableModes = supportsModes(entry);

  let requestedMode: ThemeMode | null = null;
  if (canOverride && prefs.mode) {
    if (prefs.mode === "light" || prefs.mode === "dark") {
      requestedMode = prefs.mode;
    }
  } else if (!canOverride && settings.theme.force_global) {
    requestedMode = settings.theme.default === slug ? bootFallbackMode(slug) : null;
  }

  let mode: ThemeMode;
  if (requestedMode && availableModes.includes(requestedMode)) {
    mode = requestedMode;
  } else if (availableModes.length === 0) {
    mode = requestedMode ?? bootFallbackMode(slug);
  } else if (availableModes.length === 1) {
    mode = availableModes[0];
  } else {
    const systemPrefersDark = prefersDark();
    const preferredBySystem = systemPrefersDark ? "dark" : "light";
    if (availableModes.includes(preferredBySystem)) {
      mode = preferredBySystem;
    } else {
      mode = availableModes[0];
    }
  }

  const source = entry?.source ?? "bootswatch";

  return { slug, mode, source };
};

const findStylesheetHref = (slug: string): string | null => {
  if (slug in BOOTSWATCH_THEME_HREFS) {
    return BOOTSWATCH_THEME_HREFS[slug];
  }
  return null;
};

const applySelection = (selection: ThemeSelection): void => {
  if (typeof document === "undefined") return;

  const link = ensureThemeLink();
  const href = findStylesheetHref(selection.slug);

  if (link && href) {
    if (link.href !== href) {
      link.href = href;
    }
  }

  const html = document.documentElement;
  html.setAttribute("data-theme", selection.slug);
  html.setAttribute("data-mode", selection.mode);
  html.setAttribute("data-theme-source", selection.source);

  currentSelection = selection;
};

const refreshTheme = (): void => {
  const selection = resolveThemeSelection();
  if (
    !currentSelection ||
    currentSelection.slug !== selection.slug ||
    currentSelection.mode !== selection.mode ||
    currentSelection.source !== selection.source
  ) {
    applySelection(selection);
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
    const manifest = await fetchJson<ThemeManifest>("/api/settings/ui/themes");
    if (manifest && Array.isArray(manifest.themes)) {
      manifestCache = manifest;
    }

    const settingsResponse = await fetchJson<{ config?: { ui?: ThemeSettings } }>("/api/settings/ui");
    if (settingsResponse?.config?.ui) {
      settingsCache = settingsResponse.config.ui;
      notifySettingsListeners();
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
  manifestCache = clone(manifest);
  refreshTheme();
};

export const updateThemeSettings = (settings: ThemeSettings): void => {
  settingsCache = clone(settings);
  refreshTheme();
  notifySettingsListeners();
};

export const updateThemePrefs = (prefs: ThemeUserPrefs): void => {
  prefsCache = clone(prefs);
  refreshTheme();
  notifyPrefsListeners();
};

export const getCurrentTheme = (): ThemeSelection => {
  if (currentSelection) return currentSelection;
  const initialSlug =
    sanitizeSlug(DEFAULT_THEME_MANIFEST.defaults.dark) ??
    sanitizeSlug(DEFAULT_THEME_MANIFEST.defaults.light) ??
    "slate";
  const entry = findManifestEntry(DEFAULT_THEME_MANIFEST, initialSlug);
  const initialMode = supportsModes(entry)[0] ?? bootFallbackMode(initialSlug);
  currentSelection = { slug: initialSlug, mode: initialMode, source: entry?.source ?? "bootswatch" };
  applySelection(currentSelection);
  applyDesignTokens();
  return currentSelection;
};

export const getCachedThemeSettings = (): ThemeSettings => clone(settingsCache);

export const getCachedThemePrefs = (): ThemeUserPrefs => clone(prefsCache);

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

export const toggleThemeMode = (): ThemeMode => {
  const selection = resolveThemeSelection();
  const entry = findManifestEntry(manifestCache, selection.slug);
  const availableModes = supportsModes(entry);
  if (availableModes.length < 2) {
    applySelection(selection);
    applyDesignTokens();
    return selection.mode;
  }

  let nextMode: ThemeMode = selection.mode === "dark" ? "light" : "dark";
  if (!availableModes.includes(nextMode)) {
    const fallback = availableModes.find((mode) => mode !== selection.mode);
    if (fallback) {
      nextMode = fallback;
    }
  }

  if (!settingsCache.theme.allow_user_override || settingsCache.theme.force_global) {
    currentSelection = { ...selection, mode: nextMode };
    applySelection(currentSelection);
    applyDesignTokens();
    notifySettingsListeners();
    return nextMode;
  }

  prefsCache = {
    ...prefsCache,
    mode: nextMode,
  };
  notifyPrefsListeners();
  refreshTheme();
  return nextMode;
};

// Apply default theme immediately when running in a browser to reduce FOUC.
if (typeof document !== "undefined") {
  getCurrentTheme();
}
