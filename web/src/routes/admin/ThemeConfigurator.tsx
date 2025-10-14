import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { baseHeaders } from "../../lib/api";
import {
  DEFAULT_THEME_SETTINGS,
  type ThemeManifest,
  type ThemeSettings,
  type ThemeMode,
} from "./themeData";
import {
  getCachedThemeManifest,
  onThemeManifestChange,
  updateThemeManifest,
  updateThemeSettings,
} from "../../theme/themeManager";
import { Link } from "react-router-dom";
import { useToast } from "../../components/toast/ToastProvider";

type FormState = {
  theme: string;
  mode: "light" | "dark";
  allowOverride: boolean;
  forceGlobal: boolean;
  overrides: Record<string, string>;
  designer: {
    storage: "browser" | "filesystem";
    filesystemPath: string;
  };
  loginLayout: "layout_1" | "layout_2" | "layout_3";
};

type ThemeSettingsResponse = {
  ok?: boolean;
  etag?: string;
  config?: { ui?: ThemeSettings };
};

type SaveResponse = {
  ok?: boolean;
  message?: string;
  note?: string;
  config?: { ui?: ThemeSettings };
  etag?: string;
  current_etag?: string;
};

type Mutable<T> = T extends object ? { -readonly [K in keyof T]: Mutable<T[K]> } : T;

type ThemeOption = {
  slug: string;
  label: string;
  source: string;
  modes: ThemeMode[];
  defaultMode: ThemeMode;
  variants?: ThemeManifest["themes"][number]["variants"];
};

const toStoredOverrides = (source: Record<string, string>): ThemeSettings["theme"]["overrides"] => {
  const base = { ...DEFAULT_THEME_SETTINGS.theme.overrides } as Mutable<
    typeof DEFAULT_THEME_SETTINGS.theme.overrides
  >;
  Object.entries(source).forEach(([key, value]) => {
    (base as Record<string, string>)[key] = value;
  });
  return base as ThemeSettings["theme"]["overrides"];
};

function buildInitialForm(settings: ThemeSettings): FormState {
  const mutable = settings as Mutable<ThemeSettings>;
  const designer = mutable.theme.designer ?? {
    storage: "filesystem",
    filesystem_path: "/opt/phpgrc/shared/themes",
  };
  const forceGlobal = Boolean(mutable.theme.force_global);
  const mode = mutable.theme.mode === "light" ? "light" : "dark";
  const layoutRaw = mutable.theme.login?.layout;
  const loginLayout: FormState["loginLayout"] =
    layoutRaw === "layout_2" || layoutRaw === "layout_3" ? layoutRaw : "layout_1";
  return {
    theme: String(mutable.theme.default),
    mode,
    forceGlobal,
    allowOverride: !forceGlobal,
    overrides: { ...mutable.theme.overrides } as Record<string, string>,
    designer: {
      storage: designer.storage === "browser" ? "browser" : "filesystem",
      filesystemPath:
        typeof designer.filesystem_path === "string" && designer.filesystem_path.trim() !== ""
          ? designer.filesystem_path
          : "/opt/phpgrc/shared/themes",
    },
    loginLayout,
  };
}

function hasChanges(form: FormState, baseline: FormState | null): boolean {
  if (!baseline) return true;
  if (form.theme !== baseline.theme) return true;
  if (form.mode !== baseline.mode) return true;
  if (form.forceGlobal !== baseline.forceGlobal) return true;
  if (form.loginLayout !== baseline.loginLayout) return true;
  if (form.designer.storage !== baseline.designer.storage) return true;
  if (form.designer.filesystemPath !== baseline.designer.filesystemPath) return true;
  const keys = new Set([...Object.keys(form.overrides), ...Object.keys(baseline.overrides)]);
  for (const key of keys) {
    if ((form.overrides[key] ?? "") !== (baseline.overrides[key] ?? "")) {
      return true;
    }
  }
  return false;
}

async function parseJson<T>(res: Response): Promise<T | null> {
  try {
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

export default function ThemeConfigurator(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [readOnly, setReadOnly] = useState(false);
  const toast = useToast();
  const { success: showSuccess, info: showInfo, warning: showWarning, danger: showDanger } = toast;

  const [manifest, setManifest] = useState<ThemeManifest>(() => getCachedThemeManifest());
  const [form, setForm] = useState<FormState>(buildInitialForm(DEFAULT_THEME_SETTINGS));
  const etagRef = useRef<string | null>(null);
  const snapshotRef = useRef<FormState | null>(buildInitialForm(DEFAULT_THEME_SETTINGS));
  const defaultLoginLayout = DEFAULT_THEME_SETTINGS.theme.login?.layout;
  const settingsRef = useRef<ThemeSettings>({
    ...DEFAULT_THEME_SETTINGS,
    theme: {
      ...DEFAULT_THEME_SETTINGS.theme,
      overrides: { ...DEFAULT_THEME_SETTINGS.theme.overrides },
      designer: { ...DEFAULT_THEME_SETTINGS.theme.designer },
      login: {
        layout:
          defaultLoginLayout === "layout_2" || defaultLoginLayout === "layout_3"
            ? defaultLoginLayout
            : "layout_1",
      },
    },
  } as ThemeSettings);

  const themeOptions: ThemeOption[] = useMemo(() => {
    const entries = [...manifest.themes, ...manifest.packs];
    const normalizeMode = (value: unknown): ThemeMode | null =>
      value === "light" || value === "dark" ? value : null;

    return entries.map((entry) => {
      const supportedModes = Array.isArray(entry.supports?.mode) ? entry.supports.mode : [];
      const modes = supportedModes
        .map((mode) => normalizeMode(mode))
        .filter((mode): mode is ThemeMode => mode !== null);
      const modeSet = modes.length > 0 ? modes : (["light"] as ThemeMode[]);

      const defaultModeRaw = normalizeMode((entry as { default_mode?: ThemeMode }).default_mode);
      const defaultMode = defaultModeRaw ?? (modeSet.includes("light") ? "light" : "dark");

      const variants = (entry as { variants?: ThemeManifest["themes"][number]["variants"] })?.variants;
      const sourceLabel =
        entry.source === "bootswatch" ? "Bootswatch" : entry.source === "custom" ? "Custom" : "Pack";

      return {
        slug: entry.slug,
        label: entry.name,
        source: sourceLabel,
        modes: modeSet,
        defaultMode,
        variants,
      };
    });
  }, [manifest]);

  const currentThemeOption = useMemo(
    () => themeOptions.find((option) => option.slug === form.theme),
    [form.theme, themeOptions]
  );

  const availableModes: ThemeMode[] = currentThemeOption?.modes ?? (["light"] as ThemeMode[]);
  const variantLabels = {
    light: currentThemeOption?.variants?.light?.name ?? "Primary",
    dark: currentThemeOption?.variants?.dark?.name ?? "Dark",
  };

  const loginLayoutOptions = useMemo(
    () => [
      {
        value: "layout_1" as const,
        label: "Layout 1",
        description: "Centered form with logo stacked above the fields.",
        preview: (
          <div className="d-flex flex-column align-items-center gap-2" aria-hidden="true">
            <div className="rounded-circle bg-primary opacity-75" style={{ width: "40px", height: "40px" }} />
            <div className="w-100 bg-body border border-light-subtle rounded-3 shadow-sm p-3 vstack gap-2">
              <div className="bg-body-secondary rounded-2" style={{ height: "10px" }} />
              <div className="bg-body-secondary rounded-2" style={{ height: "10px" }} />
              <div className="bg-primary text-white fw-semibold rounded-pill px-2 py-1 small text-center">Sign in</div>
            </div>
          </div>
        ),
      },
      {
        value: "layout_2" as const,
        label: "Layout 2",
        description: "Compact logo above left-aligned fields with arrow submit.",
        preview: (
          <div className="vstack gap-2" aria-hidden="true">
            <div className="d-flex align-items-center gap-2">
              <div className="rounded-circle bg-primary opacity-75" style={{ width: "28px", height: "28px" }} />
              <div className="bg-body-secondary rounded-2 flex-grow-1" style={{ height: "8px", maxWidth: "72px" }} />
            </div>
            <div className="d-flex align-items-center gap-3">
              <div className="flex-grow-1 vstack gap-2">
                <div className="bg-body-secondary rounded-2" style={{ height: "10px" }} />
                <div className="bg-body-secondary rounded-2" style={{ height: "10px" }} />
              </div>
              <div
                className="d-flex align-items-center justify-content-center bg-primary text-white rounded-circle"
                style={{ width: "36px", height: "36px" }}
              >
                <span aria-hidden="true">{"\u2192"}</span>
              </div>
            </div>
          </div>
        ),
      },
      {
        value: "layout_3" as const,
        label: "Layout 3",
        description: "Animated two-step sign-in with sequential email and password prompts.",
        preview: (
          <div className="vstack gap-2" aria-hidden="true">
            <div className="bg-body border border-light-subtle rounded-3 shadow-sm p-2">
              <div className="bg-body-secondary rounded-2 mx-auto" style={{ height: "10px", width: "70%" }} />
            </div>
            <div className="bg-body border border-light-subtle rounded-3 shadow-sm p-2 vstack gap-2">
              <div className="bg-body-secondary rounded-2" style={{ height: "10px", width: "80%" }} />
              <div className="d-flex align-items-center justify-content-between">
                <div className="bg-body-secondary rounded-2" style={{ height: "10px", width: "60%" }} />
                <div
                  className="d-flex align-items-center justify-content-center bg-primary text-white rounded-circle"
                  style={{ width: "24px", height: "24px" }}
                >
                  <span aria-hidden="true">{"\u2192"}</span>
                </div>
              </div>
            </div>
          </div>
        ),
      },
    ],
    []
  );

  useEffect(() => {
    const unsubscribe = onThemeManifestChange((next) => {
      setManifest(next);
    });
    return unsubscribe;
  }, []);

  const applySettings = useCallback((settings: ThemeSettings) => {
    settingsRef.current = {
      ...settings,
      theme: {
        ...settings.theme,
        overrides: { ...settings.theme.overrides },
        designer: {
          ...settings.theme.designer,
        },
        login: {
          layout:
            settings.theme.login?.layout === "layout_2" || settings.theme.login?.layout === "layout_3"
              ? (settings.theme.login?.layout as FormState["loginLayout"])
              : "layout_1",
        },
      },
    } as ThemeSettings;
    const nextForm = buildInitialForm(settings);
    snapshotRef.current = nextForm;
    setForm(nextForm);
    updateThemeSettings(settings);
  }, []);

  const previewTheme = useCallback((next: FormState) => {
    const base = settingsRef.current;
    const preview: ThemeSettings = {
      ...base,
      theme: {
        ...base.theme,
        default: next.theme as ThemeSettings["theme"]["default"],
        mode: next.mode as ThemeSettings["theme"]["mode"],
        allow_user_override: (!next.forceGlobal) as ThemeSettings["theme"]["allow_user_override"],
        force_global: next.forceGlobal as ThemeSettings["theme"]["force_global"],
        overrides: toStoredOverrides(next.overrides),
        designer: {
          storage: next.designer.storage,
          filesystem_path: next.designer.filesystemPath,
        },
        login: {
          layout: next.loginLayout,
        },
      },
    };
    updateThemeSettings(preview);
  }, []);

  const loadManifest = useCallback(async () => {
    try {
      const res = await fetch("/api/settings/ui/themes", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });
      if (!res.ok) {
        showWarning("Theme manifest unavailable; using bundled defaults.");
        return;
      }
      const body = await parseJson<ThemeManifest>(res);
      if (body && Array.isArray(body.themes)) {
        setManifest(body);
        updateThemeManifest(body);
      }
    } catch {
      showWarning("Theme manifest unavailable; using bundled defaults.");
    }
  }, [showWarning]);

  const loadSettings = useCallback(async (options?: { preserveMessage?: boolean }) => {
    setLoading(true);
    const shouldNotify = !options?.preserveMessage;
    try {
      await loadManifest();

      const res = await fetch("/api/settings/ui", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });

      if (res.status === 403) {
        setReadOnly(true);
        if (shouldNotify) {
          showWarning("You do not have permission to adjust theme settings.");
        }
        etagRef.current = null;
        snapshotRef.current = buildInitialForm(DEFAULT_THEME_SETTINGS);
        setForm(buildInitialForm(DEFAULT_THEME_SETTINGS));
        updateThemeSettings(DEFAULT_THEME_SETTINGS);
        return;
      }

      if (!res.ok) {
        if (shouldNotify) {
          showWarning("Failed to load theme settings. Using defaults.");
        }
        etagRef.current = null;
        snapshotRef.current = buildInitialForm(DEFAULT_THEME_SETTINGS);
        setForm(buildInitialForm(DEFAULT_THEME_SETTINGS));
        updateThemeSettings(DEFAULT_THEME_SETTINGS);
        return;
      }

      etagRef.current = res.headers.get("ETag");
      const body = await parseJson<ThemeSettingsResponse>(res);
      const settings = body?.config?.ui ?? DEFAULT_THEME_SETTINGS;
      applySettings(settings);
    } catch {
      if (shouldNotify) {
        showWarning("Failed to load theme settings. Using defaults.");
      }
      etagRef.current = null;
      snapshotRef.current = buildInitialForm(DEFAULT_THEME_SETTINGS);
      setForm(buildInitialForm(DEFAULT_THEME_SETTINGS));
      updateThemeSettings(DEFAULT_THEME_SETTINGS);
    } finally {
      setLoading(false);
    }
  }, [applySettings, loadManifest, showWarning]);

  const loadSettingsRef = useRef(loadSettings);

  useEffect(() => {
    loadSettingsRef.current = loadSettings;
  }, [loadSettings]);

  useEffect(() => {
    void loadSettingsRef.current();
  }, []);

  const onChangeTheme = (value: string) => {
    const option = themeOptions.find((item) => item.slug === value);
    setForm((prev) => {
      const availableModes = option?.modes ?? (["light"] as ThemeMode[]);
      const nextMode = availableModes.includes(prev.mode)
        ? prev.mode
        : option?.defaultMode ?? availableModes[0];
      const next = { ...prev, theme: value, mode: nextMode };
      previewTheme(next);
      return next;
    });
  };

  const onSelectMode = (mode: ThemeMode) => {
    const option = themeOptions.find((item) => item.slug === form.theme);
    const allowed = option?.modes ?? (["light"] as ThemeMode[]);
    if (!allowed.includes(mode)) return;
    setForm((prev) => {
      if (prev.mode === mode) return prev;
      const next = { ...prev, mode };
      previewTheme(next);
      return next;
    });
  };

  const onToggleForce = (value: boolean) => {
    setForm((prev) => {
      const next = {
        ...prev,
        forceGlobal: value,
        allowOverride: !value,
      };
      previewTheme(next);
      return next;
    });
  };

  const onSelectLoginLayout = (value: FormState["loginLayout"]) => {
    setForm((prev) => {
      if (prev.loginLayout === value) return prev;
      const next = { ...prev, loginLayout: value };
      previewTheme(next);
      return next;
    });
  };

  const onDesignerStorageChange = (value: "browser" | "filesystem") => {
    setForm((prev) => {
      const next = {
        ...prev,
        designer: {
          storage: value,
          filesystemPath: prev.designer.filesystemPath,
        },
      };
      previewTheme(next);
      return next;
    });
  };

  const onDesignerPathChange = (value: string) => {
    setForm((prev) => {
      const next = {
        ...prev,
        designer: {
          ...prev.designer,
          filesystemPath: value,
        },
      };
      previewTheme(next);
      return next;
    });
  };

  const resetToBaseline = () => {
    const snapshot = snapshotRef.current ?? buildInitialForm(DEFAULT_THEME_SETTINGS);
    setForm(snapshot);
    previewTheme(snapshot);
    showInfo("Reverted to last saved values.");
  };

  const handleSave = async () => {
    if (readOnly) {
      showWarning("Read-only mode: theme settings cannot be changed.");
      return;
    }
    const snapshot = snapshotRef.current;
    if (!hasChanges(form, snapshot)) {
      showInfo("No changes to save.");
      return;
    }
    const etag = etagRef.current;
    if (!etag) {
      await loadSettings();
      showInfo("Settings version refreshed. Please retry.");
      return;
    }

    setSaving(true);

    try {
      const payload = {
        ui: {
          theme: {
            default: form.theme,
            mode: form.mode,
            allow_user_override: !form.forceGlobal,
            force_global: form.forceGlobal,
            overrides: form.overrides,
            designer: {
              storage: form.designer.storage,
              filesystem_path: form.designer.filesystemPath,
            },
            login: {
              layout: form.loginLayout,
            },
          },
        },
      };

      const res = await fetch("/api/settings/ui", {
        method: "PUT",
        credentials: "same-origin",
        headers: baseHeaders({
          "Content-Type": "application/json",
          "If-Match": etag,
        }),
        body: JSON.stringify(payload),
      });

      const body = await parseJson<SaveResponse>(res);

      if (res.status === 409) {
        const nextEtag = body?.current_etag ?? res.headers.get("ETag");
        if (nextEtag) {
          etagRef.current = nextEtag;
        }
        showWarning("Settings changed elsewhere. Reloaded latest values.");
        await loadSettings({ preserveMessage: true });
        return;
      }

      if (!res.ok) {
        throw new Error(`Save failed (HTTP ${res.status}).`);
      }

      const nextEtag = res.headers.get("ETag") ?? body?.etag ?? null;
      if (nextEtag) {
        etagRef.current = nextEtag;
      }

      if (body?.config?.ui) {
        applySettings(body.config.ui);
      } else {
        snapshotRef.current = form;
        await loadSettings({ preserveMessage: true });
      }

      const msg = typeof body?.message === "string" ? body.message : "Theme settings saved.";
      showSuccess(msg);
    } catch (err) {
      showDanger(err instanceof Error ? err.message : "Save failed.");
    } finally {
      setSaving(false);
    }
  };

  const disabled = loading || saving || readOnly;

  return (
    <section className="card mb-4" aria-label="theme-configurator">
      <div className="card-header d-flex justify-content-between align-items-center">
        <strong>Theme</strong>
        <div className="d-flex gap-2 flex-wrap justify-content-end">
          <Link to="/admin/settings/theme-designer" className="btn btn-outline-primary btn-sm">
            Theme Designer
          </Link>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => void loadSettings()}
            disabled={loading || saving}
          >
            Reload
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={resetToBaseline}
            disabled={disabled}
          >
            Reset
          </button>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={() => void handleSave()}
            disabled={disabled || !hasChanges(form, snapshotRef.current)}
          >
            {saving ? "Saving…" : "Save"}
          </button>
        </div>
      </div>
      <div className="card-body vstack gap-4">
        {loading ? (
          <p>Loading theme settings…</p>
        ) : (
          <>
            <fieldset className="vstack gap-3" disabled={disabled}>
              <div>
                <label htmlFor="themeSelect" className="form-label fw-semibold">
                  Default theme
                </label>
                <select
                  id="themeSelect"
                  className="form-select"
                  value={form.theme}
                  onChange={(event) => onChangeTheme(event.target.value)}
                >
                  {themeOptions.map((opt) => (
                    <option key={opt.slug} value={opt.slug}>
                      {opt.label} ({opt.source})
                    </option>
                  ))}
                </select>
                <div className="form-text">
                  Users can select from available themes when the global theme is not forced.
                </div>
              </div>

              <div>
                <span className="form-label fw-semibold d-block">Default mode</span>
                <div className="d-flex gap-4">
                  <div className="form-check form-check-inline">
                    <input
                      id="themeModeLight"
                      type="radio"
                      name="themeMode"
                      className="form-check-input"
                      value="light"
                      checked={form.mode === "light"}
                      disabled={!availableModes.includes("light")}
                      onChange={() => onSelectMode("light")}
                    />
                    <label htmlFor="themeModeLight" className="form-check-label">
                      {variantLabels.light}
                    </label>
                  </div>
                  <div className="form-check form-check-inline">
                    <input
                      id="themeModeDark"
                      type="radio"
                      name="themeMode"
                      className="form-check-input"
                      value="dark"
                      checked={form.mode === "dark"}
                      disabled={!availableModes.includes("dark")}
                      onChange={() => onSelectMode("dark")}
                    />
                    <label htmlFor="themeModeDark" className="form-check-label">
                      {variantLabels.dark}
                    </label>
                  </div>
                </div>
                {!availableModes.includes("dark") ? (
                  <div className="form-text">Dark mode is not available for this theme.</div>
                ) : null}
              </div>

              <div className="form-check">
                <input
                  id="forceGlobal"
                  type="checkbox"
                  className="form-check-input"
                  checked={form.forceGlobal}
                  onChange={(event) => onToggleForce(event.target.checked)}
                />
                <label htmlFor="forceGlobal" className="form-check-label">
                  Force global theme (light/dark still follows capability rules)
                </label>
              </div>

              <div className="row g-3">
                <div className="col-md-4">
                  <label htmlFor="designerStorage" className="form-label fw-semibold">
                    Custom theme storage
                  </label>
                  <select
                    id="designerStorage"
                    className="form-select"
                    value={form.designer.storage}
                    onChange={(event) => onDesignerStorageChange(event.target.value as "browser" | "filesystem")}
                  >
                    <option value="filesystem">Server (shared)</option>
                    <option value="browser">Browser (per device)</option>
                  </select>
                </div>
                <div className="col-md-8">
                  {form.designer.storage === "filesystem" ? (
                    <div className="vstack gap-1">
                      <label htmlFor="designerPath" className="form-label fw-semibold mb-0">
                        Filesystem path
                      </label>
                      <input
                        id="designerPath"
                        type="text"
                        className="form-control"
                        value={form.designer.filesystemPath}
                        onChange={(event) => onDesignerPathChange(event.target.value)}
                      />
                      <div className="form-text">
                        Themes are written to this directory on the application server.
                      </div>
                    </div>
                  ) : (
                    <div className="alert alert-warning mb-0" role="note">
                      Themes save to the current browser only and are not shared across users.
                    </div>
                  )}
                </div>
              </div>
            </fieldset>

            <fieldset className="vstack gap-3" disabled={disabled}>
              <legend className="form-label fw-semibold mb-0">Login layout</legend>
              <p className="text-secondary small mb-2">
                Choose how the authentication page should present the form and branding.
              </p>
              <div className="row g-3">
                {loginLayoutOptions.map((option) => {
                  const inputId = `loginLayout-${option.value}`;
                  const selected = form.loginLayout === option.value;
                  return (
                    <div key={option.value} className="col-md-6">
                      <div
                        className={`card h-100 border-2 ${selected ? "border-primary shadow-sm" : "border-light"}`}
                      >
                        <div className="card-body vstack gap-3">
                          <div className="form-check d-flex align-items-center gap-2 mb-0">
                            <input
                              id={inputId}
                              type="radio"
                              className="form-check-input"
                              name="loginLayout"
                              value={option.value}
                              checked={selected}
                              onChange={() => onSelectLoginLayout(option.value)}
                              disabled={disabled}
                            />
                            <label htmlFor={inputId} className="form-check-label fw-semibold">
                              {option.label}
                            </label>
                          </div>
                          <p className="text-secondary small mb-0">{option.description}</p>
                          <div className="bg-body-secondary bg-opacity-25 rounded-3 p-3">
                            {option.preview}
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </fieldset>
          </>
        )}
      </div>
    </section>
  );
}
