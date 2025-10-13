import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { baseHeaders } from "../../lib/api";
import { DEFAULT_THEME_SETTINGS, type ThemeManifest, type ThemeSettings } from "./themeData";
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
  allowOverride: boolean;
  forceGlobal: boolean;
  overrides: Record<string, string>;
  designer: {
    storage: "browser" | "filesystem";
    filesystemPath: string;
  };
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
  return {
    theme: String(mutable.theme.default),
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
  };
}

function hasChanges(form: FormState, baseline: FormState | null): boolean {
  if (!baseline) return true;
  if (form.theme !== baseline.theme) return true;
  if (form.forceGlobal !== baseline.forceGlobal) return true;
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
  const settingsRef = useRef<ThemeSettings>({
    ...DEFAULT_THEME_SETTINGS,
    theme: {
      ...DEFAULT_THEME_SETTINGS.theme,
      overrides: { ...DEFAULT_THEME_SETTINGS.theme.overrides },
    },
  } as ThemeSettings);

  const themeOptions = useMemo(() => {
    const base = [...manifest.themes, ...manifest.packs];
    return base.map((theme) => ({
      slug: theme.slug,
      name: theme.source === "custom" ? `${theme.name} (Custom)` : theme.name,
      source: theme.source,
    }));
  }, [manifest]);

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
        allow_user_override: (!next.forceGlobal) as ThemeSettings["theme"]["allow_user_override"],
        force_global: next.forceGlobal as ThemeSettings["theme"]["force_global"],
        overrides: toStoredOverrides(next.overrides),
        designer: {
          storage: next.designer.storage,
          filesystem_path: next.designer.filesystemPath,
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
    setForm((prev) => {
      const next = { ...prev, theme: value };
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
            allow_user_override: !form.forceGlobal,
            force_global: form.forceGlobal,
            overrides: form.overrides,
            designer: {
              storage: form.designer.storage,
              filesystem_path: form.designer.filesystemPath,
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
                      {opt.name} ({opt.source === "bootswatch" ? "Bootswatch" : "Pack"})
                    </option>
                  ))}
                </select>
                <div className="form-text">
                  Users can select from available themes when the global theme is not forced.
                </div>
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
          </>
        )}
      </div>
    </section>
  );
}
