import { useCallback, useEffect, useMemo, useState, type CSSProperties } from "react";
import "./ThemeDesigner.css";
import { baseHeaders } from "../../lib/api";
import {
  getCachedThemeManifest,
  getCachedThemeSettings,
  onThemeSettingsChange,
  updateThemeManifest,
} from "../../theme/themeManager";
import { DEFAULT_THEME_SETTINGS, type CustomThemePack, type ThemeManifest } from "./themeData";

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

export default function ThemeDesigner(): JSX.Element {
  const [openFeature, setOpenFeature] = useState<string | null>("all");
  const [activeContext, setActiveContext] = useState<string | null>(null);
  const [activeVariant, setActiveVariant] = useState<string | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [designerConfig, setDesignerConfig] = useState(() => getCachedThemeSettings().theme.designer);
  const [customThemeName, setCustomThemeName] = useState<string>("");
  const [saveStatus, setSaveStatus] = useState<{ type: "success" | "error"; message: string } | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  useEffect(() => {
    const unsubscribe = onThemeSettingsChange((next) => {
      setDesignerConfig(next.theme.designer);
    });
    return unsubscribe;
  }, []);

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

  const handleSaveTheme = useCallback(() => {
    const name = customThemeName.trim();
    if (name === "") {
      setSaveStatus({ type: "error", message: "Enter a theme name." });
      return;
    }

    if (saveStatus) {
      setSaveStatus(null);
    }

    const slug = name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");

    if (!slug) {
      setSaveStatus({ type: "error", message: "Theme name must include alphanumeric characters." });
      return;
    }

    const manifest = getCachedThemeManifest();
    const existing = [...manifest.themes, ...manifest.packs].find((item) => item.slug === slug);
    if (existing && existing.source !== "custom") {
      setSaveStatus({ type: "error", message: "Theme name conflicts with a built-in theme." });
      return;
    }

    const variables = computeCustomVariables();
    if (Object.keys(variables).length === 0) {
      setSaveStatus({ type: "error", message: "Adjust at least one setting before saving." });
      return;
    }

    const applyPackToManifest = (pack: CustomThemePack): void => {
      const manifest = getCachedThemeManifest();
      const packs = manifest.packs.filter((entry) => entry.slug !== pack.slug);
      const nextManifest: ThemeManifest = {
        ...manifest,
        packs: [...packs, pack],
      };
      updateThemeManifest(nextManifest);
      setSaveStatus({ type: "success", message: `Saved “${name}”.` });
    };

    if (designerConfig.storage === "filesystem") {
      setIsSaving(true);
      setSaveStatus(null);
      void (async () => {
        try {
          const res = await fetch("/api/settings/ui/designer/themes", {
            method: "POST",
            credentials: "same-origin",
            headers: baseHeaders({ "Content-Type": "application/json" }),
            body: JSON.stringify({ name, slug, variables }),
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
            setSaveStatus({ type: "error", message });
            return;
          }

          const pack = (body as { pack?: CustomThemePack } | null)?.pack;
          if (pack) {
            applyPackToManifest(pack);
          } else {
            applyPackToManifest({
              slug,
              name,
              source: "custom",
              supports: { mode: ["light", "dark"] },
              variables,
            });
          }
        } catch (error) {
          setSaveStatus({
            type: "error",
            message: error instanceof Error ? error.message : "Save failed.",
          });
        } finally {
          setIsSaving(false);
        }
      })();
      return;
    }

    applyPackToManifest({
      slug,
      name,
      source: "custom",
      supports: { mode: ["light", "dark"] },
      variables,
    });
  }, [computeCustomVariables, customThemeName, designerConfig.storage, saveStatus]);

  useEffect(() => {
    setActiveContext(null);
    setActiveVariant(null);
  }, [openFeature]);

  useEffect(() => {
    setActiveVariant(null);
  }, [activeContext]);

  const variableStyles = useMemo<CSSProperties>(() => {
    const styles: CSSProperties = {};
    UNIQUE_TARGETS.forEach((target) => {
      const value = values[target.key] ?? target.defaultValue;
      (styles as Record<string, string>)[target.variable] = value;
    });
    return styles;
  }, [values]);

  const activeFeature = openFeature ? FEATURE_LOOKUP.get(openFeature) ?? null : null;
  const contextForVariants =
    activeContext && activeFeature
      ? activeFeature.contexts.find((context) => context.id === activeContext) ?? null
      : null;
  const activeVariantObj =
    activeVariant && contextForVariants
      ? contextForVariants.variants.find((variant) => variant.id === activeVariant) ?? null
      : null;

  const handleFeatureToggle = (featureId: string) => {
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
    return primaryTarget.defaultValue;
  };

  const describeSettingValue = (setting: SettingConfig, value: string): string => {
    if (setting.control === "color") {
      const trimmed = value.trim();
      return trimmed.startsWith("#") ? trimmed.toUpperCase() : trimmed;
    }
    if (setting.control === "toggle" && setting.toggleValues) {
      return value === setting.toggleValues.on ? "On" : "Off";
    }
    if (setting.control === "select" && setting.options) {
      const match = setting.options.find((option) => option.value === value);
      return match?.label ?? (value === "" ? "Inherit" : value);
    }
    return value;
  };

  const onMenuMouseLeave = () => {
    setOpenFeature(null);
    setActiveContext(null);
    setActiveVariant(null);
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
                                  <span className="theme-designer-setting-value">
                                    {describeSettingValue(setting, currentValue)}
                                  </span>
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
                                  <span className="theme-designer-setting-value">
                                    {describeSettingValue(setting, currentValue)}
                                  </span>
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
                                  <span className="theme-designer-setting-value">
                                    {describeSettingValue(setting, currentValue)}
                                  </span>
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
          <div className="theme-designer-menu-tools">
            <input
              type="text"
              className="form-control form-control-sm theme-designer-name-input"
              placeholder="Custom theme name"
              value={customThemeName}
              onChange={(event) => {
                setCustomThemeName(event.target.value);
                if (saveStatus) setSaveStatus(null);
              }}
            />
            <button
              type="button"
              className="btn btn-primary btn-sm theme-designer-save-button"
              onClick={handleSaveTheme}
              disabled={isSaving}
            >
              {isSaving ? "Saving..." : "Save Theme"}
            </button>
          </div>
        </div>
        {saveStatus && (
          <div
            className={`theme-designer-feedback theme-designer-feedback--${saveStatus.type}`}
            role="status"
          >
            {saveStatus.message}
          </div>
        )}
      </div>

      <section className="theme-designer-preview container py-4" style={variableStyles}>
        <header className="mb-4">
          <h1 className="mb-1">Theme Designer</h1>
          <p className="text-muted mb-0">
            Fine-tune Bootstrap components visually. Use the sticky menu to target a component, then adjust contextual
            colors. Changes update the preview instantly.
          </p>
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
