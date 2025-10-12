import { useEffect, useMemo, useState, type CSSProperties } from "react";
import "./ThemeDesigner.css";

type SettingTarget = {
  key: string;
  featureId: string;
  contextId: string;
  variantId: string;
  propertyId: string;
  variable: string;
  defaultValue: string;
};

type SettingConfig = {
  id: string;
  label: string;
  type: "color";
  propertyId: string;
  targets: SettingTarget[];
  scope?: string | null;
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
    type: "color",
    propertyId,
    targets: [target],
    scope: contextId,
  };
}

function createVariant(
  featureId: string,
  contextId: string,
  variant: VariantDefinition
): ThemeVariant {
  const settings = PROPERTY_DEFINITIONS.map((property) =>
    createColorSetting(featureId, contextId, variant.id, property.id, property.label)
  );

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
            type: "color" as const,
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

const AGGREGATE_FEATURE = buildAggregateFeature(TARGET_REGISTRY);
const DESIGNER_FEATURES: ThemeFeature[] = [AGGREGATE_FEATURE, ...BASE_FEATURES];
const FEATURE_LOOKUP = new Map(DESIGNER_FEATURES.map((feature) => [feature.id, feature]));

const UNIQUE_TARGETS: SettingTarget[] = Array.from(
  TARGET_REGISTRY.reduce(
    (map, target) => map.set(target.key, target),
    new Map<string, SettingTarget>()
  ).values()
);

export default function ThemeDesigner(): JSX.Element {
  const [openFeature, setOpenFeature] = useState<string | null>("all");
  const [activeContext, setActiveContext] = useState<string | null>("all");
  const [activeVariant, setActiveVariant] = useState<string | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!openFeature) {
      setActiveVariant(null);
      return;
    }
    const feature = FEATURE_LOOKUP.get(openFeature);
    const firstContext = feature?.contexts[0];
    if (!firstContext) {
      setActiveContext(null);
      setActiveVariant(null);
      return;
    }
    setActiveContext((current) => current ?? firstContext.id);
  }, [openFeature]);

  useEffect(() => {
    if (!openFeature || !activeContext) {
      setActiveVariant(null);
      return;
    }
    const feature = FEATURE_LOOKUP.get(openFeature);
    const context = feature?.contexts.find((ctx) => ctx.id === activeContext);
    const targetVariant = context?.variants[0];
    setActiveVariant((current) => {
      if (current && context?.variants.some((variant) => variant.id === current)) {
        return current;
      }
      return targetVariant?.id ?? null;
    });
  }, [openFeature, activeContext]);

  const variableStyles = useMemo<CSSProperties>(() => {
    const styles: CSSProperties = {};
    UNIQUE_TARGETS.forEach((target) => {
      const value = values[target.key] ?? target.defaultValue;
      (styles as Record<string, string>)[target.variable] = value;
    });
    return styles;
  }, [values]);

  const activeFeature = openFeature ? FEATURE_LOOKUP.get(openFeature) ?? null : null;
  const activeContextObj =
    activeFeature?.contexts.find((context) => context.id === activeContext) ?? null;
  const activeVariantObj =
    activeContextObj?.variants.find((variant) => variant.id === activeVariant) ??
    activeContextObj?.variants[0] ??
    null;

  const handleFeatureToggle = (featureId: string) => {
    setOpenFeature((current) => (current === featureId ? null : featureId));
  };

  const handleSettingChange = (setting: SettingConfig, nextValue: string) => {
    setValues((prev) => {
      const updated = { ...prev };
      const scope = setting.scope ?? null;
      setting.targets
        .filter((target) => {
          if (scope === null || scope === "all") return true;
          return target.contextId === scope;
        })
        .forEach((target) => {
        updated[target.key] = nextValue;
      });
      return updated;
    });
  };

  const getValueForSetting = (setting: SettingConfig): string => {
    const primaryTarget = setting.targets[0];
    if (!primaryTarget) return "#ffffff";
    return values[primaryTarget.key] ?? primaryTarget.defaultValue;
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
        <ul className="theme-designer-menu-list">
          {DESIGNER_FEATURES.map((feature) => (
            <li
              key={feature.id}
              className={`theme-designer-menu-item${
                openFeature === feature.id ? " theme-designer-menu-item--open" : ""
              }`}
              onMouseEnter={() => {
                setOpenFeature(feature.id);
                const firstContext = feature.contexts[0];
                setActiveContext(firstContext?.id ?? null);
                setActiveVariant(firstContext?.variants[0]?.id ?? null);
              }}
            >
              <button
                type="button"
                className="theme-designer-menu-button"
                onClick={() => handleFeatureToggle(feature.id)}
                onFocus={() => {
                  setOpenFeature(feature.id);
                  const firstContext = feature.contexts[0];
                  setActiveContext(firstContext?.id ?? null);
                  setActiveVariant(firstContext?.variants[0]?.id ?? null);
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
                                setActiveVariant(context.variants[0]?.id ?? null);
                              }}
                              onFocus={() => {
                                setActiveContext(context.id);
                                setActiveVariant(context.variants[0]?.id ?? null);
                              }}
                            >
                              {context.label}
                            </button>
                          </li>
                        ))}
                      </ul>
                    </div>

                    {activeContextObj && (
                      <div className="theme-designer-dropdown-column">
                        <span className="theme-designer-dropdown-heading">Variants</span>
                        <ul className="theme-designer-dropdown-list">
                          {activeContextObj.variants.map((variant) => (
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

                    {activeVariantObj && (
                      <div className="theme-designer-dropdown-column settings">
                        <span className="theme-designer-dropdown-heading">Settings</span>
                        <div className="theme-designer-settings">
                          {activeVariantObj.settings.map((setting) => (
                            <label key={setting.id} className="theme-designer-setting">
                              <span className="theme-designer-setting-label">{setting.label}</span>
                              <input
                                type="color"
                                className="form-control form-control-color theme-designer-setting-input"
                                value={getValueForSetting(setting)}
                                onChange={(event) => handleSettingChange(setting, event.target.value)}
                              />
                              <span className="theme-designer-setting-value">
                                {getValueForSetting(setting).toUpperCase()}
                              </span>
                            </label>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
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
