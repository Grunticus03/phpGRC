import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  useId,
  type CSSProperties,
  type FocusEvent,
  type MouseEvent,
} from "react";
import "./ThemeDesigner.css";
import { baseHeaders } from "../../lib/api";
import { getThemeAccess, deriveThemeAccess, type ThemeAccess } from "../../lib/themeAccess";
import {
  getCachedThemePrefs,
  getCachedThemeManifest,
  getCachedThemeSettings,
  getCurrentTheme,
  onThemeManifestChange,
  onThemePrefsChange,
  onThemeSettingsChange,
  toggleThemeMode,
  updateThemeManifest,
  updateThemePrefs,
} from "../../theme/themeManager";
import ConfirmModal from "../../components/modal/ConfirmModal";
import {
  DEFAULT_THEME_SETTINGS,
  type CustomThemePack,
  type ThemeManifest,
  type ThemeUserPrefs,
} from "./themeData";

type SettingTarget = {
  key: string;
  featureId: string;
  contextId: string;
  variantId: string;
  propertyId: string;
  variable: string;
  defaultValue: string;
};

type SettingControl = "color" | "select" | "toggle";

type SettingOption = {
  value: string;
  label: string;
};

type SettingConfig = {
  id: string;
  label: string;
  control: SettingControl;
  propertyId: string;
  targets: SettingTarget[];
  scope?: string | null;
  options?: SettingOption[];
  toggleValues?: { on: string; off: string };
};

type ThemeVariant = {
  id: string;
  label: string;
  settings: SettingConfig[];
};

type ThemeContext = {
  id: string;
  label: string;
  variants: ThemeVariant[];
};

type ThemeFeature = {
  id: string;
  label: string;
  description?: string;
  contexts: ThemeContext[];
};

type ThemeManifestEntry = ThemeManifest["themes"][number] | ThemeManifest["packs"][number];
type ThemeSelectionState = ReturnType<typeof getCurrentTheme>;

const isCustomPack = (entry: ThemeManifestEntry): entry is CustomThemePack => entry.source === "custom";

const FONT_OPTIONS: SettingOption[] = [
  {
    value: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    label: "System UI",
  },
  { value: '"Inter", system-ui, sans-serif', label: "Inter" },
  { value: '"Roboto", system-ui, sans-serif', label: "Roboto" },
  { value: '"Open Sans", system-ui, sans-serif', label: "Open Sans" },
  { value: '"Lato", system-ui, sans-serif', label: "Lato" },
  { value: '"Merriweather", "Georgia", serif', label: "Merriweather" },
  { value: '"Playfair Display", "Georgia", serif', label: "Playfair Display" },
  { value: '"Source Sans Pro", system-ui, sans-serif', label: "Source Sans Pro" },
  { value: '"Fira Code", "SFMono-Regular", Menlo, monospace', label: "Fira Code" },
];

const FONT_WEIGHT_OPTIONS: SettingOption[] = [
  { value: "", label: "Inherit" },
  { value: "300", label: "Light" },
  { value: "400", label: "Normal" },
  { value: "500", label: "Medium" },
  { value: "600", label: "Semibold" },
  { value: "700", label: "Bold" },
];

const FONT_SIZE_OPTIONS: SettingOption[] = [
  { value: "", label: "Inherit" },
  { value: "0.875rem", label: "Small" },
  { value: "1rem", label: "Default" },
  { value: "1.125rem", label: "Large" },
  { value: "1.25rem", label: "Extra Large" },
];

const TYPOGRAPHY_DEFAULT_FONT = FONT_OPTIONS[0]?.value ?? 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

type ContextFilter = {
  id: string;
  label: string;
  predicate: (target: SettingTarget) => boolean;
};

type VariantDefinition = {
  id: string;
  label: string;
};

type PropertyDefinition = {
  id: string;
  label: string;
};

const TARGET_REGISTRY: SettingTarget[] = [];

const VARIANT_COLOR_DEFAULTS: Record<string, string> = {
  primary: "#0d6efd",
  secondary: "#6c757d",
  success: "#198754",
  info: "#0dcaf0",
  warning: "#ffc107",
  danger: "#dc3545",
};

const VARIANT_TEXT_DEFAULTS: Record<string, string> = {
  primary: "#ffffff",
  secondary: "#ffffff",
  success: "#ffffff",
  info: "#212529",
  warning: "#212529",
  danger: "#ffffff",
};

const PROPERTY_DEFINITIONS: PropertyDefinition[] = [
  { id: "background", label: "Background" },
  { id: "text", label: "Text color" },
];

const VARIANT_DEFINITIONS: VariantDefinition[] = [
  { id: "primary", label: "Primary" },
  { id: "secondary", label: "Secondary" },
  { id: "success", label: "Success" },
  { id: "info", label: "Info" },
  { id: "warning", label: "Warning" },
  { id: "danger", label: "Danger" },
];

const HEX_COLOR_PATTERN = /^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i;
const DEFAULT_BACKGROUND_COLOR = DEFAULT_THEME_SETTINGS.theme.overrides["color.background"];

function registerTarget(target: SettingTarget): SettingTarget {
  TARGET_REGISTRY.push(target);
  return target;
}

function resolveDefaultValue(
  featureId: string,
  contextId: string,
  variantId: string,
  propertyId: string
): string {
  if (propertyId === "background") {
    return VARIANT_COLOR_DEFAULTS[variantId] ?? "#6c757d";
  }
  if (contextId === "dark" && propertyId === "text") {
    return "#f8f9fa";
  }
  return VARIANT_TEXT_DEFAULTS[variantId] ?? "#ffffff";
}

function createColorSetting(
  featureId: string,
  contextId: string,
  variantId: string,
  propertyId: string,
  label: string
): SettingConfig {
  const key = `${featureId}.${contextId}.${variantId}.${propertyId}`;
  const variable = `--td-${featureId}-${contextId}-${variantId}-${propertyId}`;
  const target: SettingTarget = registerTarget({
    key,
    featureId,
    contextId,
    variantId,
    propertyId,
    variable,
    defaultValue: resolveDefaultValue(featureId, contextId, variantId, propertyId),
  });

  return {
    id: key,
    label,
    control: "color",
    propertyId,
    targets: [target],
    scope: contextId,
  };
}

const toKebab = (value: string): string =>
  value.replace(/([a-z0-9])([A-Z])/g, "$1-$2").toLowerCase();

const slugifyThemeName = (name: string): string =>
  name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

function createSelectSetting(
  featureId: string,
  contextId: string,
  variantId: string,
  propertyId: string,
  label: string,
  options: SettingOption[],
  defaultValue: string,
  scope: string | null = contextId,
  variableOverride?: string
): SettingConfig {
  const key = `${featureId}.${contextId}.${variantId}.${propertyId}`;
  const variable = variableOverride ?? `--td-${featureId}-${contextId}-${variantId}-${toKebab(propertyId)}`;
  const target: SettingTarget = registerTarget({
    key,
    featureId,
    contextId,
    variantId,
    propertyId,
    variable,
    defaultValue,
  });

  return {
    id: key,
    label,
    control: "select",
    propertyId,
    targets: [target],
    options,
    scope,
  };
}

function createToggleSetting(
  featureId: string,
  contextId: string,
  variantId: string,
  propertyId: string,
  label: string,
  toggleValues: { on: string; off: string },
  scope: string | null = contextId,
  variableOverride?: string
): SettingConfig {
  const key = `${featureId}.${contextId}.${variantId}.${propertyId}`;
  const variable = variableOverride ?? `--td-${featureId}-${contextId}-${variantId}-${toKebab(propertyId)}`;
  const target: SettingTarget = registerTarget({
    key,
    featureId,
    contextId,
    variantId,
    propertyId,
    variable,
    defaultValue: toggleValues.off,
  });

  return {
    id: key,
    label,
    control: "toggle",
    propertyId,
    targets: [target],
    toggleValues,
    scope,
  };
}

function createTextSettings(featureId: string, contextId: string, variantId: string): SettingConfig[] {
  const settings: SettingConfig[] = [];

  settings.push(
    createSelectSetting(
      featureId,
      contextId,
      variantId,
      "fontWeight",
      "Weight",
      FONT_WEIGHT_OPTIONS,
      "",
      contextId
    )
  );

  settings.push(
    createToggleSetting(featureId, contextId, variantId, "textDecoration", "Underline", {
      on: "underline",
      off: "none",
    })
  );

  settings.push(
    createToggleSetting(featureId, contextId, variantId, "fontStyle", "Italics", {
      on: "italic",
      off: "normal",
    })
  );

  settings.push(
    createSelectSetting(
      featureId,
      contextId,
      variantId,
      "fontSize",
      "Font size",
      FONT_SIZE_OPTIONS,
      "",
      contextId
    )
  );

  return settings;
}

function createVariant(
  featureId: string,
  contextId: string,
  variant: VariantDefinition
): ThemeVariant {
  const colorSettings = PROPERTY_DEFINITIONS.map((property) =>
    createColorSetting(featureId, contextId, variant.id, property.id, property.label)
  );

  const settings = [...colorSettings, ...createTextSettings(featureId, contextId, variant.id)];

  return {
    id: variant.id,
    label: variant.label,
    settings,
  };
}

function createContext(
  featureId: string,
  contextId: string,
  label: string,
  variants: VariantDefinition[]
): ThemeContext {
  return {
    id: contextId,
    label,
    variants: variants.map((variant) => createVariant(featureId, contextId, variant)),
  };
}

function createBackgroundSetting(): SettingConfig {
  const featureId = "foundations";
  const contextId = "global";
  const variantId = "base";
  const propertyId = "pageBackground";
  const key = `${featureId}.${contextId}.${variantId}.${propertyId}`;
  const target: SettingTarget = registerTarget({
    key,
    featureId,
    contextId,
    variantId,
    propertyId,
    variable: "--td-foundations-global-base-pageBackground",
    defaultValue:
      typeof DEFAULT_BACKGROUND_COLOR === "string" && DEFAULT_BACKGROUND_COLOR.trim() !== ""
        ? DEFAULT_BACKGROUND_COLOR
        : "#10131a",
  });

  return {
    id: key,
    label: "Background color",
    control: "color",
    propertyId,
    targets: [target],
    scope: contextId,
  };
}

const FOUNDATIONS_FEATURE: ThemeFeature = {
  id: "foundations",
  label: "Foundations",
  description: "Adjust global background styling for authentication and shell views.",
  contexts: [
    {
      id: "global",
      label: "Global",
      variants: [
        {
          id: "base",
          label: "Base",
          settings: [createBackgroundSetting()],
        },
      ],
    },
  ],
};

const resolveSwatchValue = (setting: SettingConfig, value: string): string => {
  const trimmed = value.trim();
  if (HEX_COLOR_PATTERN.test(trimmed)) {
    return trimmed;
  }
  const fallback = setting.targets[0]?.defaultValue ?? "#000000";
  return typeof fallback === "string" && HEX_COLOR_PATTERN.test(fallback) ? fallback : "#000000";
};

function buildFeature(
  id: string,
  label: string,
  description: string,
  contexts: Array<{ id: string; label: string }>
): ThemeFeature {
  return {
    id,
    label,
    description,
    contexts: contexts.map((context) =>
      createContext(id, context.id, context.label, VARIANT_DEFINITIONS)
    ),
  };
}

const BASE_FEATURES: ThemeFeature[] = [
  FOUNDATIONS_FEATURE,
  buildFeature("navbars", "Navbars", "Live preview of light and dark navigation bars.", [
    { id: "light", label: "Light" },
    { id: "dark", label: "Dark" },
  ]),
  buildFeature("buttons", "Buttons", "Solid buttons rendered on light and dark backgrounds.", [
    { id: "light", label: "Light" },
    { id: "dark", label: "Dark" },
  ]),
  buildFeature("tables", "Tables", "Table rows using contextual variants.", [
    { id: "light", label: "Light" },
    { id: "dark", label: "Dark" },
  ]),
  buildFeature("pills", "Pills", "Navigation pills with contextual emphasis.", [
    { id: "light", label: "Light" },
    { id: "dark", label: "Dark" },
  ]),
  buildFeature("alerts", "Alerts", "Feedback alerts across variants.", [
    { id: "light", label: "Light" },
    { id: "dark", label: "Dark" },
  ]),
];

function buildTypographyFeature(): ThemeFeature {
  const globalVariantSettings: SettingConfig[] = [
    createSelectSetting(
      "typography",
      "global",
      "base",
      "fontFamily",
      "Font family",
      FONT_OPTIONS,
      TYPOGRAPHY_DEFAULT_FONT,
      null,
      "--td-typography-font-family"
    ),
    createSelectSetting(
      "typography",
      "global",
      "base",
      "fontWeight",
      "Base weight",
      FONT_WEIGHT_OPTIONS,
      "400",
      null,
      "--td-typography-font-weight"
    ),
    createToggleSetting(
      "typography",
      "global",
      "base",
      "fontStyle",
      "Italics",
      { on: "italic", off: "normal" },
      null,
      "--td-typography-font-style"
    ),
    createToggleSetting(
      "typography",
      "global",
      "base",
      "textDecoration",
      "Underline",
      { on: "underline", off: "none" },
      null,
      "--td-typography-text-decoration"
    ),
    createSelectSetting(
      "typography",
      "global",
      "base",
      "fontSize",
      "Font size",
      FONT_SIZE_OPTIONS,
      "",
      null,
      "--td-typography-font-size"
    ),
  ];

  return {
    id: "typography",
    label: "Typography",
    description: "Global font and text styling applied across components.",
    contexts: [
      {
        id: "global",
        label: "Global",
        variants: [
          {
            id: "base",
            label: "Base",
            settings: globalVariantSettings,
          },
        ],
      },
    ],
  };
}
function buildAggregateFeature(targets: SettingTarget[]): ThemeFeature {
  const contextFilters: ContextFilter[] = [
    { id: "all", label: "All", predicate: () => true },
    { id: "light", label: "Light", predicate: (target) => target.contextId === "light" },
    { id: "dark", label: "Dark", predicate: (target) => target.contextId === "dark" },
  ];

  const contexts: ThemeContext[] = contextFilters
    .map((context) => {
      const variants: ThemeVariant[] = VARIANT_DEFINITIONS.map((variant) => {
        const variantTargets = targets.filter(
          (target) => target.variantId === variant.id && context.predicate(target)
        );

        const settings = PROPERTY_DEFINITIONS.map((property) => {
          const propertyTargets = variantTargets.filter(
            (target) => target.propertyId === property.id
          );
          if (propertyTargets.length === 0) {
            return null;
          }
          return {
            id: `aggregate.${context.id}.${variant.id}.${property.id}`,
            label: property.label,
            control: "color",
            propertyId: property.id,
            targets: propertyTargets,
            scope: context.id,
          };
        }).filter(Boolean) as SettingConfig[];

        if (settings.length === 0) {
          return null;
        }

        return {
          id: variant.id,
          label: variant.label,
          settings,
        };
      }).filter(Boolean) as ThemeVariant[];

      if (variants.length === 0) {
        return null;
      }

      return {
        id: context.id,
        label: context.label,
        variants,
      };
    })
    .filter(Boolean) as ThemeContext[];

  return {
    id: "all",
    label: "All",
    description: "Adjust multiple components across the library in one pass.",
    contexts,
  };
}

const TYPOGRAPHY_FEATURE = buildTypographyFeature();
const aggregateTargets = TARGET_REGISTRY.filter((target) => target.featureId !== "typography");
const AGGREGATE_FEATURE = buildAggregateFeature(aggregateTargets);
const DESIGNER_FEATURES: ThemeFeature[] = [AGGREGATE_FEATURE, TYPOGRAPHY_FEATURE, ...BASE_FEATURES];
const FEATURE_LOOKUP = new Map(DESIGNER_FEATURES.map((feature) => [feature.id, feature]));

const UNIQUE_TARGETS: SettingTarget[] = Array.from(
  TARGET_REGISTRY.reduce(
    (map, target) => map.set(target.key, target),
    new Map<string, SettingTarget>()
  ).values()
);

const RGB_COLOR_PATTERN =
  /^rgba?\(\s*([0-9]{1,3}(?:\.[0-9]+)?)\s*,\s*([0-9]{1,3}(?:\.[0-9]+)?)\s*,\s*([0-9]{1,3}(?:\.[0-9]+)?)(?:\s*,\s*([0-9]*(?:\.[0-9]+)?))?\s*\)$/i;

const clamp = (value: number, min: number, max: number): number => Math.min(Math.max(value, min), max);

const toHexChannel = (value: number): string => {
  const clamped = clamp(Math.round(value), 0, 255);
  const hex = clamped.toString(16);
  return hex.length === 1 ? `0${hex}` : hex;
};

const rgbToHex = (r: number, g: number, b: number): string =>
  `#${toHexChannel(r)}${toHexChannel(g)}${toHexChannel(b)}`;

const hexToRgb = (value: string): { r: number; g: number; b: number } | null => {
  const normalized = value.trim().toLowerCase();
  if (!/^#?[0-9a-f]{3,8}$/.test(normalized)) return null;
  const trimmed = normalized.startsWith("#") ? normalized.slice(1) : normalized;
  if (trimmed.length === 3) {
    const r = parseInt(trimmed[0] + trimmed[0], 16);
    const g = parseInt(trimmed[1] + trimmed[1], 16);
    const b = parseInt(trimmed[2] + trimmed[2], 16);
    return { r, g, b };
  }
  if (trimmed.length === 6 || trimmed.length === 8) {
    const r = parseInt(trimmed.slice(0, 2), 16);
    const g = parseInt(trimmed.slice(2, 4), 16);
    const b = parseInt(trimmed.slice(4, 6), 16);
    return { r, g, b };
  }
  return null;
};

const normalizeCssColor = (raw: string | null | undefined): string | null => {
  const value = raw?.trim();
  if (!value) return null;
  if (HEX_COLOR_PATTERN.test(value)) {
    const hex = value.startsWith("#") ? value : `#${value}`;
    return hex.length === 4
      ? `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`
      : hex.toLowerCase();
  }
  const match = value.match(RGB_COLOR_PATTERN);
  if (match) {
    const [, rs, gs, bs, alphaRaw] = match;
    const alpha = typeof alphaRaw === "string" && alphaRaw !== "" ? Number.parseFloat(alphaRaw) : 1;
    if (Number.isNaN(alpha) || alpha <= 0) {
      return null;
    }
    const r = Number.parseFloat(rs ?? "");
    const g = Number.parseFloat(gs ?? "");
    const b = Number.parseFloat(bs ?? "");
    if ([r, g, b].some((component) => Number.isNaN(component))) {
      return null;
    }
    return rgbToHex(r, g, b);
  }
  return null;
};

const mixHexColors = (first: string, second: string, secondWeight: number): string => {
  const rgbFirst = hexToRgb(first);
  const rgbSecond = hexToRgb(second);
  if (!rgbFirst || !rgbSecond) {
    return first;
  }
  const weight = clamp(secondWeight, 0, 1);
  const r = rgbFirst.r * (1 - weight) + rgbSecond.r * weight;
  const g = rgbFirst.g * (1 - weight) + rgbSecond.g * weight;
  const b = rgbFirst.b * (1 - weight) + rgbSecond.b * weight;
  return rgbToHex(r, g, b);
};

const relativeLuminance = (hex: string): number => {
  const rgb = hexToRgb(hex);
  if (!rgb) return 0;
  const transform = (channel: number): number => {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };
  const r = transform(rgb.r);
  const g = transform(rgb.g);
  const b = transform(rgb.b);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

const contrastRatio = (hexA: string, hexB: string): number => {
  const l1 = relativeLuminance(hexA) + 0.05;
  const l2 = relativeLuminance(hexB) + 0.05;
  return l1 > l2 ? l1 / l2 : l2 / l1;
};

const chooseContrastingColor = (background: string, candidates: string[]): string => {
  const normalizedBackground = normalizeCssColor(background) ?? "#000000";
  let winner = "#ffffff";
  let best = -Infinity;
  candidates.forEach((candidate) => {
    const normalizedCandidate = normalizeCssColor(candidate);
    if (!normalizedCandidate) return;
    const ratio = contrastRatio(normalizedBackground, normalizedCandidate);
    if (ratio > best) {
      best = ratio;
      winner = normalizedCandidate;
    }
  });
  return winner;
};

const computeDefaultBaseValues = (): Record<string, string> => {
  const base: Record<string, string> = {};
  UNIQUE_TARGETS.forEach((target) => {
    base[target.key] = target.defaultValue;
  });
  return base;
};

const waitForThemeStylesheet = async (
  baseSlug: string,
  shouldCancel?: () => boolean
): Promise<void> => {
  if (typeof document === "undefined") return;
  const userAgent =
    typeof navigator !== "undefined" && typeof navigator.userAgent === "string"
      ? navigator.userAgent.toLowerCase()
      : "";
  if (userAgent.includes("jsdom")) {
    return;
  }
  const maxAttempts = 40;
  for (let attempts = 0; attempts < maxAttempts; attempts++) {
    if (shouldCancel?.()) {
      return;
    }
    const link = document.getElementById("phpgrc-theme-css") as HTMLLinkElement | null;
    if (link && link.href.includes(baseSlug) && link.sheet) {
      return;
    }
    const pending = document.getElementById("phpgrc-theme-css-pending") as HTMLLinkElement | null;
    if (pending && pending.href.includes(baseSlug) && pending.sheet) {
      return;
    }
    await new Promise((resolve) => {
      setTimeout(resolve, 50);
    });
  }
};

const buildBootswatchBaseValues = (): Record<string, string> => {
  if (typeof document === "undefined") return {};

  const rootStyle = getComputedStyle(document.documentElement);
  const bodyStyle = document.body ? getComputedStyle(document.body) : rootStyle;

  const getColorVar = (variable: string, fallback: string): string => {
    const fromRoot = normalizeCssColor(rootStyle.getPropertyValue(variable));
    if (fromRoot) {
      return fromRoot;
    }
    const fromBody = normalizeCssColor(bodyStyle.getPropertyValue(variable));
    return fromBody ?? fallback;
  };

  const getBodyFontFamily = (): string => {
    const cssVar = rootStyle.getPropertyValue("--bs-body-font-family").trim();
    if (cssVar) return cssVar;
    const bodyFamily = bodyStyle.fontFamily?.trim();
    return bodyFamily && bodyFamily !== "" ? bodyFamily : TYPOGRAPHY_DEFAULT_FONT;
  };

  const normalizeFontWeight = (value: string): string => {
    const trimmed = value.trim().toLowerCase();
    if (trimmed === "" || trimmed === "normal") return "400";
    if (trimmed === "bold") return "700";
    const parsed = Number.parseInt(trimmed, 10);
    if (!Number.isNaN(parsed) && parsed > 0) {
      return `${parsed}`;
    }
    return "400";
  };

  const white = getColorVar("--bs-white", "#ffffff");
  const black = getColorVar("--bs-black", "#000000");
  const bodyBackgroundFallback =
    normalizeCssColor(bodyStyle.backgroundColor) ?? DEFAULT_BACKGROUND_COLOR;
  const bodyBg = getColorVar("--bs-body-bg", bodyBackgroundFallback);
  const bodyColor = getColorVar(
    "--bs-body-color",
    normalizeCssColor(bodyStyle.color) ?? "#212529"
  );
  const emphasisColor = getColorVar("--bs-emphasis-color", black);

  const solidColors: Record<string, string> = {};
  const subtleColors: Record<string, string> = {};
  const emphasisTextColors: Record<string, string> = {};
  const solidTextColors: Record<string, string> = {};

  VARIANT_DEFINITIONS.forEach((variant) => {
    const solid = getColorVar(`--bs-${variant.id}`, VARIANT_COLOR_DEFAULTS[variant.id] ?? "#6c757d");
    solidColors[variant.id] = solid;

    const subtleFallback = mixHexColors(solid, bodyBg, 0.85);
    subtleColors[variant.id] = getColorVar(`--bs-${variant.id}-bg-subtle`, subtleFallback);

    const emphasisFallback = chooseContrastingColor(subtleColors[variant.id], [
      emphasisColor,
      bodyColor,
      black,
    ]);
    emphasisTextColors[variant.id] = getColorVar(
      `--bs-${variant.id}-text-emphasis`,
      emphasisFallback
    );

    const darkTextCandidate = emphasisColor || bodyColor || "#212529";
    const luminance = relativeLuminance(solid);
    solidTextColors[variant.id] =
      luminance < 0.55 ? white : chooseContrastingColor(solid, [darkTextCandidate, white]);
  });

  const rootFontSize = Number.parseFloat(rootStyle.fontSize) || 16;
  const bodyFontSize = Number.parseFloat(bodyStyle.fontSize) || rootFontSize;
  const fontSizeRem = `${(bodyFontSize / rootFontSize).toFixed(3)}rem`.replace(/\.?0+rem$/, "rem");

  const textDecorationLine =
    (bodyStyle.textDecorationLine ?? "").trim() ||
    (bodyStyle.textDecoration ?? "").split(" ").find((token) => token === "underline") ||
    "none";

  const base: Record<string, string> = {};

  UNIQUE_TARGETS.forEach((target) => {
    if (target.featureId === "foundations" && target.propertyId === "pageBackground") {
      base[target.key] = bodyBg;
      return;
    }

    if (target.featureId === "typography") {
      switch (target.propertyId) {
        case "fontFamily":
          base[target.key] = getBodyFontFamily();
          return;
        case "fontWeight":
          base[target.key] = normalizeFontWeight(bodyStyle.fontWeight ?? "400");
          return;
        case "fontStyle":
          base[target.key] = (bodyStyle.fontStyle ?? "normal").trim() || "normal";
          return;
        case "textDecoration":
          base[target.key] = textDecorationLine || "none";
          return;
        case "fontSize":
          base[target.key] = fontSizeRem;
          return;
        default:
          break;
      }
    }

    if (target.propertyId === "background") {
      if (target.featureId === "alerts" || target.featureId === "tables") {
        base[target.key] = subtleColors[target.variantId] ?? solidColors[target.variantId] ?? target.defaultValue;
        return;
      }
      base[target.key] = solidColors[target.variantId] ?? target.defaultValue;
      return;
    }

    if (target.propertyId === "text") {
      if (target.featureId === "alerts" || target.featureId === "tables") {
        base[target.key] = emphasisTextColors[target.variantId] ?? bodyColor;
        return;
      }

      base[target.key] = solidTextColors[target.variantId] ?? white;
      return;
    }
  });

  return base;
};

const buildDesignerValuesFromVariables = (
  variables: Record<string, string> | null | undefined
): Record<string, string> => {
  const next: Record<string, string> = {};
  if (!variables) return next;

  UNIQUE_TARGETS.forEach((target) => {
    const value = variables[target.variable];
    if (typeof value !== "string") {
      return;
    }
    const normalized = value.trim();
    if (normalized === "" || normalized === target.defaultValue) {
      return;
    }
    next[target.key] = normalized;
  });

  return next;
};

export default function ThemeDesigner(): JSX.Element {
  const [openFeature, setOpenFeature] = useState<string | null>(null);
  const [activeContext, setActiveContext] = useState<string | null>(null);
  const [activeVariant, setActiveVariant] = useState<string | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [baseValues, setBaseValues] = useState<Record<string, string>>(() => computeDefaultBaseValues());
  const [designerConfig, setDesignerConfig] = useState(() => getCachedThemeSettings().theme.designer);
  const [manifest, setManifest] = useState<ThemeManifest>(() => getCachedThemeManifest());
  const [customThemeName, setCustomThemeName] = useState<string>("");
  const [feedback, setFeedback] = useState<{ type: "success" | "error"; message: string } | null>(null);
  const [themeMenuOpen, setThemeMenuOpen] = useState(false);
  const [saveModalOpen, setSaveModalOpen] = useState(false);
  const [loadModalOpen, setLoadModalOpen] = useState(false);
  const [deleteModalOpen, setDeleteModalOpen] = useState(false);
  const [importModalOpen, setImportModalOpen] = useState(false);
  const [exportModalOpen, setExportModalOpen] = useState(false);
  const [saveBusy, setSaveBusy] = useState(false);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [importBusy, setImportBusy] = useState(false);
  const [exportBusy, setExportBusy] = useState(false);
  const [loadSelection, setLoadSelection] = useState<string>("");
  const [deleteSelection, setDeleteSelection] = useState<string>("");
  const [saveModalError, setSaveModalError] = useState<string | null>(null);
  const [loadModalError, setLoadModalError] = useState<string | null>(null);
  const [deleteModalError, setDeleteModalError] = useState<string | null>(null);
  const [importModalError, setImportModalError] = useState<string | null>(null);
  const [exportModalError, setExportModalError] = useState<string | null>(null);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importSlug, setImportSlug] = useState<string>("");
  const [exportSelection, setExportSelection] = useState<string>("");
  const [pendingOverwriteSlug, setPendingOverwriteSlug] = useState<string | null>(null);
  const [loadedThemeSlug, setLoadedThemeSlug] = useState<string | null>(null);
  const [themeSelection, setThemeSelection] = useState<ThemeSelectionState>(() => getCurrentTheme());
  const [themeAccess, setThemeAccess] = useState<ThemeAccess | null>(null);
  const accessRef = useRef<ThemeAccess | null>(null);
  const themeMenuCloseTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const previewPendingSlugRef = useRef<string | null>(null);
  const previewRestorePrefsRef = useRef<ThemeUserPrefs | null>(null);
  const skipBaseEffectRef = useRef(false);

  const loadSelectId = useId();
  const saveInputId = useId();
  const deleteSelectId = useId();
  const importFileInputId = useId();
  const importSlugInputId = useId();
  const exportSelectId = useId();

  const cancelThemeMenuClose = useCallback(() => {
    if (themeMenuCloseTimeoutRef.current !== null) {
      clearTimeout(themeMenuCloseTimeoutRef.current);
      themeMenuCloseTimeoutRef.current = null;
    }
  }, []);

  const scheduleThemeMenuClose = useCallback(() => {
    cancelThemeMenuClose();
    themeMenuCloseTimeoutRef.current = setTimeout(() => {
      setThemeMenuOpen(false);
      themeMenuCloseTimeoutRef.current = null;
    }, 120);
  }, [cancelThemeMenuClose]);

  const handleThemeMenuMouseEnter = useCallback(() => {
    cancelThemeMenuClose();
    if (!themeMenuOpen) {
      setThemeMenuOpen(true);
    }
  }, [cancelThemeMenuClose, themeMenuOpen]);

  const handleThemeMenuMouseLeave = useCallback(
    (event: MouseEvent<HTMLElement>) => {
      const next = event.relatedTarget as Node | null;
      if (event.currentTarget.contains(next)) {
        return;
      }
      scheduleThemeMenuClose();
    },
    [scheduleThemeMenuClose]
  );

  const handleThemeMenuFocus = useCallback(() => {
    cancelThemeMenuClose();
    setThemeMenuOpen(true);
  }, [cancelThemeMenuClose]);

  const handleThemeMenuBlur = useCallback((event: FocusEvent<HTMLLIElement>) => {
    const next = event.relatedTarget as Node | null;
    if (event.currentTarget.contains(next)) {
      return;
    }
    setThemeMenuOpen(false);
  }, []);

  useEffect(() => () => cancelThemeMenuClose(), [cancelThemeMenuClose]);

  useEffect(() => {
    let active = true;
    void getThemeAccess()
      .then((access) => {
        if (!active) return;
        accessRef.current = access;
        setThemeAccess(access);
      })
      .catch(() => {
        if (!active) return;
        const fallback = deriveThemeAccess([]);
        accessRef.current = fallback;
        setThemeAccess(fallback);
      });

    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    const offSettings = onThemeSettingsChange((next) => {
      setDesignerConfig(next.theme.designer);
      setThemeSelection(getCurrentTheme());
    });
    const offPrefs = onThemePrefsChange(() => {
      setThemeSelection(getCurrentTheme());
    });
    return () => {
      offSettings();
      offPrefs();
    };
  }, []);

  useEffect(() => {
    const unsubscribe = onThemeManifestChange((next) => {
      setManifest(next);
    });
    return unsubscribe;
  }, []);

  useEffect(() => {
    let cancelled = false;

    if (skipBaseEffectRef.current) {
      skipBaseEffectRef.current = false;
      return;
    }

    const applyBaseValues = async () => {
      if (typeof document === "undefined") return;

      if (themeSelection.source === "bootswatch") {
        const slugBase = themeSelection.slug.split(":")[0] ?? themeSelection.slug;
        await waitForThemeStylesheet(slugBase, () => cancelled);
        if (cancelled) return;
        const next = computeDefaultBaseValues();
        const overrides = buildBootswatchBaseValues();
        Object.assign(next, overrides);
        setBaseValues(next);

        const previewSlug = previewPendingSlugRef.current;
        const restorePrefs = previewRestorePrefsRef.current;
        const matchesPreview =
          previewSlug !== null &&
          (themeSelection.slug === previewSlug || themeSelection.slug.startsWith(`${previewSlug}:`));
        if (!cancelled && matchesPreview && restorePrefs) {
          previewPendingSlugRef.current = null;
          previewRestorePrefsRef.current = null;
          skipBaseEffectRef.current = true;
          updateThemePrefs(restorePrefs);
        }
      } else {
        setBaseValues(computeDefaultBaseValues());
      }
    };

    void applyBaseValues();

    return () => {
      cancelled = true;
    };
  }, [themeSelection.slug, themeSelection.mode, themeSelection.source]);

  const themeEntries = useMemo<ThemeManifestEntry[]>(() => {
    return [...manifest.themes, ...manifest.packs];
  }, [manifest]);

  const customEntries = useMemo<CustomThemePack[]>(() => themeEntries.filter(isCustomPack), [themeEntries]);

  const deletableCustomEntries = useMemo(
    () => customEntries.filter((entry) => (entry as { locked?: boolean }).locked !== true),
    [customEntries]
  );

  const hasCustomThemes = useMemo(
    () => deletableCustomEntries.length > 0,
    [deletableCustomEntries]
  );

  const hasAnyCustomThemes = useMemo(() => customEntries.length > 0, [customEntries]);

  const canManageTheme = themeAccess?.canManage ?? false;
  const canManageThemePacks = themeAccess?.canManagePacks ?? false;

  const canToggleMode = useMemo(() => {
    const entry = themeEntries.find((candidate) => candidate.slug === themeSelection.slug);
    if (!entry?.supports?.mode) return false;
    const supported = entry.supports.mode.filter((mode): mode is "light" | "dark" => mode === "light" || mode === "dark");
    return supported.length > 1;
  }, [themeEntries, themeSelection.slug]);

  const isDarkMode = themeSelection.mode === "dark";

  useEffect(() => {
    if (loadSelection && !themeEntries.some((entry) => entry.slug === loadSelection)) {
      setLoadSelection(themeEntries[0]?.slug ?? "");
    }
  }, [loadSelection, themeEntries]);

  useEffect(() => {
    if (deleteSelection && !deletableCustomEntries.some((entry) => entry.slug === deleteSelection)) {
      setDeleteSelection(deletableCustomEntries[0]?.slug ?? "");
    }
  }, [deletableCustomEntries, deleteSelection]);

  useEffect(() => {
    if (customEntries.length === 0) {
      setExportSelection("");
      return;
    }

    if (!customEntries.some((entry) => entry.slug === exportSelection)) {
      setExportSelection(customEntries[0]?.slug ?? "");
    }
  }, [customEntries, exportSelection]);

  const findEntryBySlug = useCallback(
    (slug: string | null | undefined): ThemeManifestEntry | null => {
      if (!slug) return null;
      return themeEntries.find((entry) => entry.slug === slug) ?? null;
    },
    [themeEntries]
  );

  const computeCustomVariables = useCallback((): Record<string, string> => {
    const collected: Record<string, string> = {};

    UNIQUE_TARGETS.forEach((target) => {
      const override = values[target.key];
      const candidate = typeof override === "string" ? override : target.defaultValue;
      const normalized = candidate == null ? "" : `${candidate}`;
      collected[target.variable] = normalized;
    });

    return collected;
  }, [values]);

  const applyPackToManifest = useCallback((pack: CustomThemePack) => {
    const manifestSnapshot = getCachedThemeManifest();
    const packs = manifestSnapshot.packs.filter((entry) => entry.slug !== pack.slug);
    const nextManifest: ThemeManifest = {
      ...manifestSnapshot,
      packs: [...packs, pack],
    };
    updateThemeManifest(nextManifest);
    setManifest(nextManifest);
  }, []);

  const removePackFromManifest = useCallback((slug: string) => {
    const manifestSnapshot = getCachedThemeManifest();
    const nextManifest: ThemeManifest = {
      ...manifestSnapshot,
      packs: manifestSnapshot.packs.filter((entry) => entry.slug !== slug),
    };
    updateThemeManifest(nextManifest);
    setManifest(nextManifest);
  }, []);

  const previewThemeSelection = useCallback(
    (slug: string, preferredMode?: "light" | "dark") => {
      const entry = findEntryBySlug(slug);
      if (!entry) return;
      if (entry.source !== "bootswatch") {
        previewPendingSlugRef.current = null;
        previewRestorePrefsRef.current = null;
        return;
      }

      const supportedModes: ("light" | "dark")[] = Array.isArray(entry.supports?.mode)
        ? entry.supports.mode.filter((mode): mode is "light" | "dark" => mode === "light" || mode === "dark")
        : ["light", "dark"];

      const defaultModeRaw =
        (entry as { default_mode?: string } | null)?.default_mode ??
        (entry as { supports?: { mode?: string[] } } | null)?.supports?.mode?.[0];
      const defaultMode: "light" | "dark" | null =
        defaultModeRaw === "light" || defaultModeRaw === "dark" ? defaultModeRaw : null;

      const candidateModes: ("light" | "dark")[] = [];

      if (preferredMode && supportedModes.includes(preferredMode)) {
        candidateModes.push(preferredMode);
      }
      if (supportedModes.includes(themeSelection.mode) && !candidateModes.includes(themeSelection.mode)) {
        candidateModes.push(themeSelection.mode);
      }
      if (defaultMode && supportedModes.includes(defaultMode) && !candidateModes.includes(defaultMode)) {
        candidateModes.push(defaultMode);
      }
      supportedModes.forEach((mode) => {
        if (!candidateModes.includes(mode)) {
          candidateModes.push(mode);
        }
      });

      const nextMode = candidateModes[0] ?? "light";
      const currentPrefs = getCachedThemePrefs();
      previewPendingSlugRef.current = slug;
      previewRestorePrefsRef.current = currentPrefs;
      skipBaseEffectRef.current = false;

      const nextPrefs: ThemeUserPrefs = {
        ...currentPrefs,
        theme: slug,
        mode: nextMode,
        overrides: { ...currentPrefs.overrides },
        sidebar: {
          ...currentPrefs.sidebar,
          order: [...currentPrefs.sidebar.order],
          hidden: [...currentPrefs.sidebar.hidden],
        },
      };
      updateThemePrefs(nextPrefs);
    },
    [findEntryBySlug, themeSelection.mode]
  );

  const handleOpenLoadModal = useCallback(() => {
    setFeedback(null);
    setLoadModalError(null);
    setThemeMenuOpen(false);
    const initial = loadSelection || loadedThemeSlug || themeEntries[0]?.slug || "";
    setLoadSelection(initial);
    setLoadModalOpen(true);
  }, [loadSelection, loadedThemeSlug, themeEntries]);

  const handleOpenSaveModal = useCallback(() => {
    if (!canManageTheme) {
      setThemeMenuOpen(false);
      setFeedback({ type: "error", message: "You do not have permission to save themes." });
      return;
    }
    setFeedback(null);
    setSaveModalError(null);
    setPendingOverwriteSlug(null);
    if (customThemeName.trim() === "" && loadedThemeSlug) {
      const entry = findEntryBySlug(loadedThemeSlug);
      if (entry) {
        setCustomThemeName(entry.name);
      }
    }
    setThemeMenuOpen(false);
    setSaveModalOpen(true);
  }, [canManageTheme, customThemeName, findEntryBySlug, loadedThemeSlug]);

  const handleOpenDeleteModal = useCallback(() => {
    if (!canManageTheme) {
      setThemeMenuOpen(false);
      setFeedback({ type: "error", message: "You do not have permission to delete themes." });
      return;
    }
    setFeedback(null);
    setDeleteModalError(null);
    setPendingOverwriteSlug(null);
    setThemeMenuOpen(false);
    const initial =
      deletableCustomEntries.find((entry) => entry.slug === deleteSelection)?.slug ??
      deletableCustomEntries[0]?.slug ??
      "";
    setDeleteSelection(initial);
    setDeleteModalOpen(true);
  }, [canManageTheme, deletableCustomEntries, deleteSelection]);

  const handleOpenImportModal = useCallback(() => {
    if (designerConfig.storage !== "filesystem") {
      setThemeMenuOpen(false);
      setFeedback({ type: "error", message: "Import requires filesystem storage." });
      return;
    }
    if (!canManageThemePacks) {
      setThemeMenuOpen(false);
      setFeedback({ type: "error", message: "You do not have permission to import themes." });
      return;
    }

    setThemeMenuOpen(false);
    setFeedback(null);
    setImportModalError(null);
    setImportFile(null);
    setImportSlug("");
    setImportModalOpen(true);
  }, [canManageThemePacks, designerConfig.storage]);

  const handleOpenExportModal = useCallback(() => {
    if (designerConfig.storage !== "filesystem") {
      setThemeMenuOpen(false);
      setFeedback({ type: "error", message: "Export requires filesystem storage." });
      return;
    }
    if (!canManageThemePacks) {
      setThemeMenuOpen(false);
      setFeedback({ type: "error", message: "You do not have permission to export themes." });
      return;
    }
    if (!hasAnyCustomThemes) {
      setThemeMenuOpen(false);
      setFeedback({ type: "error", message: "No custom themes are available to export." });
      return;
    }

    setThemeMenuOpen(false);
    setFeedback(null);
    setExportModalError(null);
    const initial = exportSelection || customEntries[0]?.slug || "";
    setExportSelection(initial);
    setExportModalOpen(true);
  }, [canManageThemePacks, customEntries, designerConfig.storage, exportSelection, hasAnyCustomThemes]);

  const handleCloseLoadModal = useCallback(() => {
    setLoadModalOpen(false);
    setLoadModalError(null);
  }, []);

  const handleCloseSaveModal = useCallback(() => {
    setSaveModalOpen(false);
    setSaveModalError(null);
    setPendingOverwriteSlug(null);
  }, []);

  const handleCloseImportModal = useCallback(() => {
    if (importBusy) {
      return;
    }
    setImportModalOpen(false);
    setImportModalError(null);
    setImportFile(null);
    setImportSlug("");
  }, [importBusy]);

  const handleCloseExportModal = useCallback(() => {
    if (exportBusy) {
      return;
    }
    setExportModalOpen(false);
    setExportModalError(null);
  }, [exportBusy]);

  const handleCloseDeleteModal = useCallback(() => {
    setDeleteModalOpen(false);
    setDeleteModalError(null);
  }, []);

  const handleConfirmLoad = useCallback(() => {
    setLoadModalError(null);
    setFeedback(null);

    const slug = loadSelection || loadedThemeSlug || "";
    const entry = findEntryBySlug(slug);
    if (!entry) {
      setLoadModalError("Select a theme to load.");
      return;
    }

    const entryDefaultMode = (entry as { default_mode?: string } | null)?.default_mode;
    const preferredMode =
      entryDefaultMode === "light" || entryDefaultMode === "dark" ? entryDefaultMode : undefined;
    previewThemeSelection(entry.slug, preferredMode);

    const variables = (entry as { variables?: Record<string, string> | null }).variables ?? null;
    setValues(buildDesignerValuesFromVariables(variables));
    setCustomThemeName(entry.name);
    setLoadedThemeSlug(entry.slug);
    setFeedback({ type: "success", message: `Loaded “${entry.name}”.` });
    setLoadModalOpen(false);
  }, [findEntryBySlug, loadSelection, loadedThemeSlug, previewThemeSelection]);

  const handleConfirmSave = useCallback(async () => {
    setSaveModalError(null);
    setFeedback(null);

    if (!canManageThemePacks) {
      setSaveModalError("You do not have permission to save themes.");
      return;
    }

    const trimmedName = customThemeName.trim();
    if (trimmedName === "") {
      setSaveModalError("Enter a theme name.");
      return;
    }

    const slug = slugifyThemeName(trimmedName);
    if (!slug) {
      setSaveModalError("Theme name must include alphanumeric characters.");
      return;
    }

    const existing = findEntryBySlug(slug);
    if (existing && existing.source !== "custom") {
      setSaveModalError("Theme name conflicts with a built-in theme.");
      return;
    }

    const variables = computeCustomVariables();
    if (Object.keys(variables).length === 0) {
      setSaveModalError("Adjust at least one setting before saving.");
      return;
    }

    if (existing && existing.source === "custom" && pendingOverwriteSlug !== slug) {
      setPendingOverwriteSlug(slug);
      setSaveModalError("A custom theme with this name already exists. Choose “Overwrite” to replace it.");
      return;
    }

    setSaveBusy(true);
    setPendingOverwriteSlug(null);

    const commit = (pack: CustomThemePack): void => {
      applyPackToManifest(pack);
    };

    try {
      if (designerConfig.storage === "filesystem") {
        const res = await fetch("/settings/ui/designer/themes", {
          method: "POST",
          credentials: "same-origin",
          headers: baseHeaders({ "Content-Type": "application/json" }),
          body: JSON.stringify({ name: trimmedName, slug, variables }),
        });

        let body: unknown = null;
        try {
          body = await res.json();
        } catch {
          body = null;
        }

        if (!res.ok) {
          const message =
            typeof (body as { message?: string } | null)?.message === "string"
              ? (body as { message: string }).message
              : `Save failed (HTTP ${res.status}).`;
          throw new Error(message);
        }

        const pack = (body as { pack?: CustomThemePack } | null)?.pack;
        if (pack) {
          commit(pack);
        } else {
          commit({
            slug,
            name: trimmedName,
            source: "custom",
            supports: { mode: ["light", "dark"] },
            variables,
          });
        }
      } else {
        commit({
          slug,
          name: trimmedName,
          source: "custom",
          supports: { mode: ["light", "dark"] },
          variables,
        });
      }

      setFeedback({ type: "success", message: `Saved “${trimmedName}”.` });
      setSaveModalOpen(false);
      setCustomThemeName(trimmedName);
      setLoadedThemeSlug(slug);
    } catch (error) {
      const message = error instanceof Error ? error.message : "Save failed.";
      setSaveModalError(message);
      return;
    } finally {
    setSaveBusy(false);
  }
}, [
    applyPackToManifest,
    canManageThemePacks,
    computeCustomVariables,
    customThemeName,
    designerConfig.storage,
    findEntryBySlug,
    pendingOverwriteSlug,
  ]);

  const handleConfirmImport = useCallback(async () => {
    if (designerConfig.storage !== "filesystem") {
      setImportModalError("Import requires filesystem storage.");
      return;
    }

    if (!canManageThemePacks) {
      setImportModalError("You do not have permission to import themes.");
      return;
    }

    if (!importFile) {
      setImportModalError("Select a theme file to import.");
      return;
    }

    setImportBusy(true);
    setImportModalError(null);
    setFeedback(null);

    try {
      const formData = new FormData();
      formData.append("file", importFile);
      const trimmedSlug = importSlug.trim();
      if (trimmedSlug !== "") {
        formData.append("slug", trimmedSlug);
      }

      const res = await fetch("/settings/ui/designer/themes/import", {
        method: "POST",
        credentials: "same-origin",
        headers: baseHeaders(),
        body: formData,
      });

      let body: unknown = null;
      try {
        body = await res.json();
      } catch {
        body = null;
      }

      if (!res.ok) {
        const message =
          typeof (body as { message?: string } | null)?.message === "string"
            ? (body as { message: string }).message
            : `Import failed (HTTP ${res.status}).`;
        throw new Error(message);
      }

      const pack = (body as { pack?: CustomThemePack } | null)?.pack;
      if (pack) {
        applyPackToManifest(pack);
        setCustomThemeName(pack.name);
        setLoadedThemeSlug(pack.slug);
      }

      setImportModalOpen(false);
      setImportFile(null);
      setImportSlug("");
      setFeedback({ type: "success", message: pack ? `Imported “${pack.name}”.` : "Theme imported." });
    } catch (error) {
      const message = error instanceof Error ? error.message : "Import failed.";
      setImportModalError(message);
      return;
    } finally {
      setImportBusy(false);
    }
  }, [applyPackToManifest, canManageThemePacks, designerConfig.storage, importFile, importSlug]);

  const handleConfirmExport = useCallback(async () => {
    if (designerConfig.storage !== "filesystem") {
      setExportModalError("Export requires filesystem storage.");
      return;
    }

    if (!canManageThemePacks) {
      setExportModalError("You do not have permission to export themes.");
      return;
    }

    if (!exportSelection) {
      setExportModalError("Select a custom theme to export.");
      return;
    }

    setExportBusy(true);
    setExportModalError(null);
    setFeedback(null);

    try {
      const res = await fetch(`/settings/ui/designer/themes/${encodeURIComponent(exportSelection)}/export`, {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });

      if (!res.ok) {
        let message = `Export failed (HTTP ${res.status}).`;
        try {
          const errorBody = (await res.clone().json()) as { message?: string } | null;
          if (typeof errorBody?.message === "string") {
            message = errorBody.message;
          }
        } catch {
          // ignore parse errors
        }
        throw new Error(message);
      }

      const blob = await res.blob();
      const disposition = res.headers.get("Content-Disposition") ?? "";
      const match = disposition.match(/filename="?([^";]+)"?/i);
      const filename = match && match[1] ? match[1] : `${exportSelection}.theme.json`;

      const url = window.URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      document.body.removeChild(anchor);
      window.URL.revokeObjectURL(url);

      const entry = findEntryBySlug(exportSelection);
      setFeedback({ type: "success", message: entry ? `Exported “${entry.name}”.` : "Theme exported." });
      setExportModalOpen(false);
    } catch (error) {
      const message = error instanceof Error ? error.message : "Export failed.";
      setExportModalError(message);
      return;
    } finally {
      setExportBusy(false);
    }
  }, [canManageThemePacks, designerConfig.storage, exportSelection, findEntryBySlug]);

  const handleConfirmDelete = useCallback(async () => {
    setDeleteModalError(null);
    setFeedback(null);

    if (!canManageThemePacks) {
      setDeleteModalError("You do not have permission to delete themes.");
      return;
    }

    if (!deleteSelection) {
      setDeleteModalError("Select a theme to delete.");
      return;
    }

    const entry = findEntryBySlug(deleteSelection);
    if (!entry) {
      setDeleteModalError("Theme not found.");
      return;
    }

    if (entry.source !== "custom") {
      setDeleteModalError("Built-in themes cannot be deleted.");
      return;
    }

    const slug = entry.slug;
    const name = entry.name;

    setDeleteBusy(true);

    try {
      if (designerConfig.storage === "filesystem") {
        const res = await fetch(`/settings/ui/designer/themes/${slug}`, {
          method: "DELETE",
          credentials: "same-origin",
          headers: baseHeaders(),
        });

        let body: unknown = null;
        try {
          body = await res.json();
        } catch {
          body = null;
        }

        if (!res.ok) {
          const message =
            typeof (body as { message?: string } | null)?.message === "string"
              ? (body as { message: string }).message
              : `Delete failed (HTTP ${res.status}).`;
          throw new Error(message);
        }
      }

      removePackFromManifest(slug);

      if (loadedThemeSlug === slug) {
        setLoadedThemeSlug(null);
      }

      if (slugifyThemeName(customThemeName) === slug) {
        setCustomThemeName("");
      }

      setFeedback({ type: "success", message: `Deleted “${name}”.` });
      setDeleteModalOpen(false);
      setDeleteSelection("");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Delete failed.";
      setDeleteModalError(message);
      return;
    } finally {
      setDeleteBusy(false);
    }
  }, [
    canManageThemePacks,
    customThemeName,
    deleteSelection,
    designerConfig.storage,
    findEntryBySlug,
    loadedThemeSlug,
    removePackFromManifest,
  ]);

  useEffect(() => {
    if (!openFeature) {
      setActiveContext(null);
      setActiveVariant(null);
      return;
    }
    const feature = FEATURE_LOOKUP.get(openFeature);
    if (!feature) {
      setActiveContext(null);
      setActiveVariant(null);
      return;
    }
    setActiveContext((current) => {
      if (current && feature.contexts.some((context) => context.id === current)) {
        return current;
      }
      return feature.contexts[0]?.id ?? null;
    });
  }, [openFeature]);

  useEffect(() => {
    if (!openFeature) {
      setActiveVariant(null);
      return;
    }
    const feature = FEATURE_LOOKUP.get(openFeature);
    if (!feature) {
      setActiveVariant(null);
      return;
    }
    const context =
      feature.contexts.find((candidate) => candidate.id === activeContext) ?? feature.contexts[0] ?? null;
    if (!context) {
      setActiveVariant(null);
      setActiveContext(null);
      return;
    }
    if (context.id !== activeContext) {
      setActiveContext(context.id);
    }
    setActiveVariant((current) => {
      if (current && context.variants.some((variant) => variant.id === current)) {
        return current;
      }
      return context.variants[0]?.id ?? null;
    });
  }, [activeContext, openFeature]);

  const variableStyles = useMemo<CSSProperties>(() => {
    const styles: CSSProperties = {};
    UNIQUE_TARGETS.forEach((target) => {
      const override = values[target.key];
      const fallback = baseValues[target.key] ?? target.defaultValue;
      const value = typeof override === "string" ? override : fallback;
      if (typeof value === "string") {
        (styles as Record<string, string>)[target.variable] = value;
      }
    });
    return styles;
  }, [baseValues, values]);

  const activeFeature = openFeature ? FEATURE_LOOKUP.get(openFeature) ?? null : null;
  const contextForVariants =
    activeContext && activeFeature
      ? activeFeature.contexts.find((context) => context.id === activeContext) ?? null
      : null;
  const activeVariantObj =
    activeVariant && contextForVariants
      ? contextForVariants.variants.find((variant) => variant.id === activeVariant) ?? null
      : null;

  const handleModeSelect = useCallback(
    (mode: "light" | "dark") => {
      if (!canToggleMode) return;
      if (themeSelection.mode === mode) return;
      const nextMode = toggleThemeMode();
      setThemeSelection((prev) => ({ ...prev, mode: nextMode }));
    },
    [canToggleMode, themeSelection.mode]
  );

  const handleFeatureToggle = (featureId: string) => {
    setThemeMenuOpen(false);
    setOpenFeature((current) => (current === featureId ? null : featureId));
  };

  const handleSettingChange = (setting: SettingConfig, rawValue: string) => {
    setValues((prev) => {
      const updated = { ...prev };
      const scope = setting.scope ?? null;
      const nextValue =
        setting.control === "toggle" && setting.toggleValues
          ? rawValue === "on"
            ? setting.toggleValues.on
            : setting.toggleValues.off
          : setting.control === "color"
            ? rawValue.toLowerCase()
            : rawValue;

      setting.targets
        .filter((target) => {
          if (scope === null || scope === "all") return true;
          return target.contextId === scope;
        })
        .forEach((target) => {
          const defaultValue = target.defaultValue;
          const normalizedNext =
            typeof nextValue === "string" ? nextValue : nextValue == null ? "" : String(nextValue);
          const normalizedDefault =
            typeof defaultValue === "string" ? defaultValue : defaultValue == null ? "" : String(defaultValue);

          if (normalizedNext === "" || normalizedNext === normalizedDefault) {
            delete updated[target.key];
          } else {
            updated[target.key] = normalizedNext;
          }
        });
      return updated;
    });
  };

  const resolveSettingValue = (setting: SettingConfig): string => {
    const primaryTarget = setting.targets[0];
    if (!primaryTarget) return "";
    const storedValue = values[primaryTarget.key];
    if (typeof storedValue === "string") {
      return storedValue;
    }
    const baseValue = baseValues[primaryTarget.key];
    if (typeof baseValue === "string") {
      return baseValue;
    }
    return primaryTarget.defaultValue;
  };

  const onMenuMouseLeave = () => {
    setOpenFeature(null);
    setActiveContext(null);
    setActiveVariant(null);
    setThemeMenuOpen(false);
  };

  return (
    <div className="theme-designer">
      <div
        className="theme-designer-menu shadow-sm border-bottom"
        role="navigation"
        aria-label="Theme designer controls"
        onMouseLeave={onMenuMouseLeave}
      >
        <div className="theme-designer-menu-inner">
          <ul className="theme-designer-menu-list">
            <li
              className={`theme-designer-menu-item theme-designer-menu-item--theme${
                themeMenuOpen ? " theme-designer-menu-item--open" : ""
              }`}
              onMouseEnter={handleThemeMenuMouseEnter}
              onFocus={handleThemeMenuFocus}
              onMouseLeave={handleThemeMenuMouseLeave}
              onBlur={handleThemeMenuBlur}
            >
              <button
                type="button"
                className="theme-designer-menu-button"
                onClick={() => {
                  cancelThemeMenuClose();
                  setThemeMenuOpen((current) => !current);
                }}
                aria-haspopup="true"
                aria-expanded={themeMenuOpen}
              >
                Theme
              </button>

              {themeMenuOpen && (
                <div
                  className="theme-designer-dropdown level-1 theme-designer-theme-dropdown"
                  onMouseEnter={handleThemeMenuMouseEnter}
                  onMouseLeave={handleThemeMenuMouseLeave}
                >
                  <div className="theme-designer-theme-panel">
                    <ul className="theme-designer-dropdown-list theme-designer-theme-actions">
                      <li>
                        <button
                          type="button"
                          className="theme-designer-dropdown-link"
                          onClick={handleOpenLoadModal}
                        >
                          Load…
                        </button>
                      </li>
                      <li>
                        <button
                          type="button"
                          className="theme-designer-dropdown-link"
                          onClick={handleOpenSaveModal}
                          disabled={!canManageTheme}
                        >
                          Save…
                        </button>
                      </li>
                      <li>
                        <button
                          type="button"
                          className="theme-designer-dropdown-link"
                          onClick={handleOpenDeleteModal}
                          disabled={!canManageTheme || !hasCustomThemes}
                        >
                          Delete…
                        </button>
                      </li>
                      <li>
                        <button
                          type="button"
                          className="theme-designer-dropdown-link"
                          onClick={handleOpenImportModal}
                          disabled={!canManageThemePacks || designerConfig.storage !== "filesystem"}
                        >
                          Import…
                        </button>
                      </li>
                      <li>
                        <button
                          type="button"
                          className="theme-designer-dropdown-link"
                          onClick={handleOpenExportModal}
                          disabled={!canManageThemePacks || designerConfig.storage !== "filesystem" || !hasAnyCustomThemes}
                        >
                          Export…
                        </button>
                      </li>
                    </ul>
                  </div>
                </div>
              )}
            </li>
          {DESIGNER_FEATURES.map((feature) => (
            <li
              key={feature.id}
              className={`theme-designer-menu-item${
                openFeature === feature.id ? " theme-designer-menu-item--open" : ""
              }`}
              onMouseEnter={() => {
                if (openFeature !== feature.id) {
                  setOpenFeature(feature.id);
                }
              }}
            >
              <button
                type="button"
                className="theme-designer-menu-button"
                onClick={() => handleFeatureToggle(feature.id)}
                onFocus={() => {
                  setOpenFeature(feature.id);
                }}
                aria-haspopup="true"
                aria-expanded={openFeature === feature.id}
              >
                {feature.label}
              </button>

              {openFeature === feature.id && (
                <div className="theme-designer-dropdown level-1">
                  <div className="theme-designer-dropdown-panel">
                    <div className="theme-designer-dropdown-column">
                      <span className="theme-designer-dropdown-heading">Contexts</span>
                      <ul className="theme-designer-dropdown-list">
                        {feature.contexts.map((context) => (
                          <li key={context.id}>
                            <button
                              type="button"
                              className={`theme-designer-dropdown-link${
                                activeContext === context.id ? " is-active" : ""
                              }`}
                              onMouseEnter={() => {
                                setActiveContext(context.id);
                              }}
                              onFocus={() => {
                                setActiveContext(context.id);
                              }}
                            >
                              {context.label}
                            </button>
                          </li>
                        ))}
                      </ul>
                    </div>

                    {contextForVariants && (
                      <div className="theme-designer-dropdown-column">
                        <span className="theme-designer-dropdown-heading">Variants</span>
                        <ul className="theme-designer-dropdown-list">
                          {contextForVariants.variants.map((variant) => (
                            <li key={variant.id}>
                              <button
                                type="button"
                                className={`theme-designer-dropdown-link${
                                  activeVariant === variant.id ? " is-active" : ""
                                }`}
                                onMouseEnter={() => setActiveVariant(variant.id)}
                                onFocus={() => setActiveVariant(variant.id)}
                              >
                                {variant.label}
                              </button>
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}

                    {activeVariantObj && activeVariant && (
                      <div className="theme-designer-dropdown-column settings">
                        <span className="theme-designer-dropdown-heading">Settings</span>
                        <div className="theme-designer-settings">
                          {activeVariantObj.settings.map((setting) => {
                            const currentValue = resolveSettingValue(setting);

                            if (setting.control === "color") {
                              const swatchValue = resolveSwatchValue(setting, currentValue);
                              const labelId = `setting-label-${setting.id}`;
                              return (
                                <div key={setting.id} className="theme-designer-setting">
                                  <span id={labelId} className="theme-designer-setting-label">
                                    {setting.label}
                                  </span>
                                  <div className="theme-designer-color-inputs">
                                    <input
                                      type="color"
                                      className="form-control form-control-color theme-designer-setting-input"
                                      value={swatchValue}
                                      onChange={(event) => handleSettingChange(setting, event.target.value)}
                                      aria-labelledby={labelId}
                                    />
                                  </div>
                                </div>
                              );
                            }

                            if (setting.control === "select") {
                              return (
                                <label key={setting.id} className="theme-designer-setting">
                                  <span className="theme-designer-setting-label">{setting.label}</span>
                                  <select
                                    className="form-select form-select-sm theme-designer-setting-select"
                                    value={currentValue}
                                    onChange={(event) => handleSettingChange(setting, event.target.value)}
                                  >
                                    {setting.options?.map((option) => (
                                      <option key={option.value} value={option.value}>
                                        {option.label}
                                      </option>
                                    ))}
                                  </select>
                                </label>
                              );
                            }

                            if (setting.control === "toggle" && setting.toggleValues) {
                              const checked = currentValue === setting.toggleValues.on;
                              return (
                                <div key={setting.id} className="theme-designer-setting">
                                  <span className="theme-designer-setting-label">{setting.label}</span>
                                  <div className="form-check form-switch theme-designer-toggle">
                                    <input
                                      type="checkbox"
                                      className="form-check-input"
                                      role="switch"
                                      checked={checked}
                                      onChange={(event) =>
                                        handleSettingChange(setting, event.target.checked ? "on" : "off")
                                      }
                                    />
                                  </div>
                                </div>
                              );
                            }

                            return null;
                          })}
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
        {canToggleMode ? (
          <div className="theme-designer-mode-toggle ms-auto" role="group" aria-label="Theme mode">
            <div className="btn-group btn-group-sm" role="group" aria-label="Theme mode toggle">
              <button
                type="button"
                className={`btn btn-outline-primary${!isDarkMode ? " active" : ""}`}
                aria-pressed={!isDarkMode}
                onClick={() => handleModeSelect("light")}
              >
                Primary
              </button>
              <button
                type="button"
                className={`btn btn-outline-primary${isDarkMode ? " active" : ""}`}
                aria-pressed={isDarkMode}
                onClick={() => handleModeSelect("dark")}
              >
                Dark
              </button>
            </div>
          </div>
        ) : null}
        </div>
        {feedback && (
          <div
            className={`theme-designer-feedback theme-designer-feedback--${feedback.type}`}
            role="status"
          >
            {feedback.message}
          </div>
        )}
      </div>

      <ConfirmModal
        open={loadModalOpen}
        title="Load Theme"
        confirmLabel="Load"
        onConfirm={handleConfirmLoad}
        onCancel={handleCloseLoadModal}
      >
        <div className="mb-3">
          <label htmlFor={loadSelectId} className="form-label">
            Choose a theme
          </label>
          <select
            id={loadSelectId}
            className="form-select"
            value={loadSelection}
            onChange={(event) => setLoadSelection(event.target.value)}
          >
            {themeEntries.map((entry) => (
              <option key={entry.slug} value={entry.slug}>
                {entry.name}
              </option>
            ))}
          </select>
        </div>
        {loadModalError && <div className="text-danger small">{loadModalError}</div>}
      </ConfirmModal>

      <ConfirmModal
        open={saveModalOpen}
        title="Save Theme"
        confirmLabel={pendingOverwriteSlug ? "Overwrite" : "Save"}
        confirmTone={pendingOverwriteSlug ? "danger" : "primary"}
        busy={saveBusy}
        onConfirm={handleConfirmSave}
        onCancel={handleCloseSaveModal}
        disableBackdropClose={saveBusy}
        confirmDisabled={!canManageThemePacks}
      >
        <div className="mb-3">
          <label htmlFor={saveInputId} className="form-label">
            Theme name
          </label>
          <input
            id={saveInputId}
            type="text"
            className="form-control"
            value={customThemeName}
            onChange={(event) => {
              setCustomThemeName(event.target.value);
              setSaveModalError(null);
              setPendingOverwriteSlug(null);
            }}
            disabled={saveBusy || !canManageThemePacks}
            placeholder="Enter a unique name"
          />
        </div>
        {pendingOverwriteSlug && (
          <div className="alert alert-warning mb-2" role="alert">
            A custom theme with this name already exists. Saving again will overwrite it.
          </div>
        )}
        {saveModalError && <div className="text-danger small">{saveModalError}</div>}
      </ConfirmModal>

      <ConfirmModal
        open={deleteModalOpen}
        title="Delete Theme"
        confirmLabel="Delete"
        confirmTone="danger"
        busy={deleteBusy}
        onConfirm={handleConfirmDelete}
        onCancel={handleCloseDeleteModal}
        disableBackdropClose={deleteBusy}
        confirmDisabled={!canManageThemePacks}
      >
        {deletableCustomEntries.length > 0 ? (
          <>
            <div className="mb-3">
              <label htmlFor={deleteSelectId} className="form-label">
                Choose a custom theme
              </label>
              <select
                id={deleteSelectId}
                className="form-select"
                value={deleteSelection}
                onChange={(event) => setDeleteSelection(event.target.value)}
                disabled={deleteBusy || !canManageThemePacks}
              >
                {deletableCustomEntries.map((entry) => (
                  <option key={entry.slug} value={entry.slug}>
                    {entry.name}
                  </option>
                ))}
              </select>
            </div>
            <p className="mb-0 text-muted small">
              This permanently removes the saved theme and any overrides associated with it.
            </p>
          </>
        ) : (
          <p className="mb-0 text-muted small">No custom themes are available to delete.</p>
        )}
        {deleteModalError && <div className="text-danger small mt-2">{deleteModalError}</div>}
      </ConfirmModal>

      <ConfirmModal
        open={importModalOpen}
        title="Import Theme"
        confirmLabel="Import"
        busy={importBusy}
        onConfirm={handleConfirmImport}
        onCancel={handleCloseImportModal}
        disableBackdropClose={importBusy}
        confirmDisabled={!canManageThemePacks || designerConfig.storage !== "filesystem"}
      >
        <div className="mb-3">
          <label htmlFor={importFileInputId} className="form-label">
            Theme file
          </label>
          <input
            id={importFileInputId}
            type="file"
            className="form-control"
            accept=".json,application/json"
            onChange={(event) => {
              const nextFile = event.target.files?.[0] ?? null;
              setImportFile(nextFile);
            }}
            disabled={importBusy}
          />
        </div>
        <div className="mb-3">
          <label htmlFor={importSlugInputId} className="form-label">
            Slug override (optional)
          </label>
          <input
            id={importSlugInputId}
            type="text"
            className="form-control"
            value={importSlug}
            onChange={(event) => setImportSlug(event.target.value)}
            disabled={importBusy}
            placeholder="Leave blank to use the packaged slug"
          />
          <div className="form-text">Slugs may include lowercase letters, numbers, and hyphens.</div>
        </div>
        {importModalError && <div className="text-danger small">{importModalError}</div>}
      </ConfirmModal>

      <ConfirmModal
        open={exportModalOpen}
        title="Export Theme"
        confirmLabel="Export"
        busy={exportBusy}
        onConfirm={handleConfirmExport}
        onCancel={handleCloseExportModal}
        disableBackdropClose={exportBusy}
        confirmDisabled={!canManageThemePacks || designerConfig.storage !== "filesystem" || !hasAnyCustomThemes}
      >
        <div className="mb-3">
          <label htmlFor={exportSelectId} className="form-label">
            Choose a custom theme
          </label>
          <select
            id={exportSelectId}
            className="form-select"
            value={exportSelection}
            onChange={(event) => setExportSelection(event.target.value)}
            disabled={exportBusy}
          >
            {customEntries.map((entry) => (
              <option key={entry.slug} value={entry.slug}>
                {entry.name}
              </option>
            ))}
          </select>
        </div>
        <p className="text-muted small">
          Exported themes can be imported into other phpGRC installations without additional steps.
        </p>
        {exportModalError && <div className="text-danger small">{exportModalError}</div>}
      </ConfirmModal>

      <section className="theme-designer-preview container py-4" style={variableStyles}>
        <header className="mb-4">
          <h1 className="mb-1">Theme Designer</h1>
        </header>

        <section className="mb-5">
          <h2 className="h4 mb-3">Navbars</h2>
          <nav
            className="navbar navbar-expand-lg mb-3"
            data-feature="navbars"
            data-context="light"
            data-variant="primary"
          >
            <div className="container-fluid">
              <a className="navbar-brand" href="#nav-light">
                Light Primary
              </a>
              <button className="navbar-toggler" type="button">
                <span className="navbar-toggler-icon" />
              </button>
              <div className="collapse navbar-collapse show">
                <ul className="navbar-nav me-auto mb-2 mb-lg-0">
                  <li className="nav-item">
                    <a className="nav-link active" aria-current="page" href="#nav-light-home">
                      Home
                    </a>
                  </li>
                  <li className="nav-item">
                    <a className="nav-link" href="#nav-light-link">
                      Link
                    </a>
                  </li>
                  <li className="nav-item">
                    <a className="nav-link disabled" aria-disabled="true" href="#nav-light-disabled">
                      Disabled
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </nav>

          <nav
            className="navbar navbar-expand-lg"
            data-feature="navbars"
            data-context="dark"
            data-variant="primary"
          >
            <div className="container-fluid">
              <a className="navbar-brand" href="#nav-dark">
                Dark Primary
              </a>
              <button className="navbar-toggler" type="button">
                <span className="navbar-toggler-icon" />
              </button>
              <div className="collapse navbar-collapse show">
                <ul className="navbar-nav me-auto mb-2 mb-lg-0">
                  <li className="nav-item">
                    <a className="nav-link active" aria-current="page" href="#nav-dark-home">
                      Home
                    </a>
                  </li>
                  <li className="nav-item">
                    <a className="nav-link" href="#nav-dark-link">
                      Link
                    </a>
                  </li>
                  <li className="nav-item">
                    <a className="nav-link disabled" aria-disabled="true" href="#nav-dark-disabled">
                      Disabled
                    </a>
                  </li>
                </ul>
              </div>
            </div>
          </nav>
        </section>

        <section className="mb-5">
          <h2 className="h4 mb-3">Buttons</h2>
          <div className="d-flex flex-wrap gap-2 align-items-center mb-3 p-3 border rounded">
            {VARIANT_DEFINITIONS.map((variant) => (
              <button
                key={`btn-light-${variant.id}`}
                type="button"
                className="btn"
                data-feature="buttons"
                data-context="light"
                data-variant={variant.id}
              >
                {variant.label}
              </button>
            ))}
          </div>
          <div className="d-flex flex-wrap gap-2 align-items-center p-3 border rounded bg-dark">
            {VARIANT_DEFINITIONS.map((variant) => (
              <button
                key={`btn-dark-${variant.id}`}
                type="button"
                className="btn"
                data-feature="buttons"
                data-context="dark"
                data-variant={variant.id}
              >
                {variant.label}
              </button>
            ))}
          </div>
        </section>

        <section className="mb-5">
          <h2 className="h4 mb-3">Tables</h2>
          <div className="row g-3">
            <div className="col-lg-6">
              <table className="table table-bordered" data-feature="tables" data-context="light" data-variant="primary">
                <thead>
                  <tr>
                    <th>Light Primary</th>
                    <th>Value</th>
                  </tr>
                </thead>
                <tbody>
                  {VARIANT_DEFINITIONS.map((variant, index) => (
                    <tr key={`table-light-${variant.id}`} data-variant={variant.id}>
                      <td>{variant.label}</td>
                      <td>{(index + 1) * 42}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="col-lg-6">
              <table className="table table-bordered" data-feature="tables" data-context="dark" data-variant="primary">
                <thead>
                  <tr>
                    <th>Dark Primary</th>
                    <th>Value</th>
                  </tr>
                </thead>
                <tbody>
                  {VARIANT_DEFINITIONS.map((variant, index) => (
                    <tr key={`table-dark-${variant.id}`} data-variant={variant.id}>
                      <td>{variant.label}</td>
                      <td>{(index + 1) * 58}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section className="mb-5">
          <h2 className="h4 mb-3">Pills & Badges</h2>
          <div className="row g-3">
            <div className="col-lg-6">
              <ul className="nav nav-pills flex-column gap-2" data-feature="pills" data-context="light" data-variant="primary">
                {VARIANT_DEFINITIONS.map((variant) => (
                  <li className="nav-item" key={`pill-light-${variant.id}`}>
                    <a className="nav-link" href={`#pill-light-${variant.id}`} data-variant={variant.id}>
                      {variant.label}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
            <div className="col-lg-6">
              <ul className="nav nav-pills flex-column gap-2 bg-dark p-3 rounded" data-feature="pills" data-context="dark" data-variant="primary">
                {VARIANT_DEFINITIONS.map((variant) => (
                  <li className="nav-item" key={`pill-dark-${variant.id}`}>
                    <a className="nav-link" href={`#pill-dark-${variant.id}`} data-variant={variant.id}>
                      {variant.label}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </section>

        <section className="mb-5">
          <h2 className="h4 mb-3">Alerts</h2>
          <div className="row g-3">
            <div className="col-lg-6">
              {VARIANT_DEFINITIONS.map((variant) => (
                <div
                  key={`alert-light-${variant.id}`}
                  role="alert"
                  className="theme-designer-alert"
                  data-feature="alerts"
                  data-context="light"
                  data-variant={variant.id}
                >
                  <strong>{variant.label}:</strong> This is a contextual alert with adjustable background and text colors.
                </div>
              ))}
            </div>
            <div className="col-lg-6">
              {VARIANT_DEFINITIONS.map((variant) => (
                <div
                  key={`alert-dark-${variant.id}`}
                  role="alert"
                  className="theme-designer-alert"
                  data-feature="alerts"
                  data-context="dark"
                  data-variant={variant.id}
                >
                  <strong>{variant.label}:</strong> Alerts adapt for dark surfaces as you update the palette.
                </div>
              ))}
            </div>
          </div>
        </section>
      </section>
    </div>
  );
}
