import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { baseHeaders } from "../../lib/api";
import {
  DEFAULT_THEME_SETTINGS,
  DEFAULT_USER_PREFS,
  type ThemeManifest,
  type ThemeSettings,
  type ThemeUserPrefs,
} from "../admin/themeData";
import {
  getCachedThemeManifest,
  onThemeManifestChange,
  updateThemeManifest,
  updateThemePrefs,
  updateThemeSettings,
} from "../../theme/themeManager";

type UserPrefsResponse = {
  ok?: boolean;
  etag?: string;
  prefs?: ThemeUserPrefs;
};

type SaveResponse = {
  ok?: boolean;
  message?: string;
  note?: string;
  prefs?: ThemeUserPrefs;
  etag?: string;
  current_etag?: string;
};

type PrefForm = {
  theme: string | null;
  mode: "light" | "dark" | null;
  overrides: Record<string, string>;
  sidebar: {
    collapsed: boolean;
    width: number;
    order: string[];
  };
};

const formToPrefs = (form: PrefForm): ThemeUserPrefs => {
  const overrides = { ...DEFAULT_USER_PREFS.overrides, ...form.overrides };

  const prefs = {
    theme: form.theme,
    mode: form.mode,
    overrides,
    sidebar: {
      collapsed: form.sidebar.collapsed,
      width: form.sidebar.width,
      order: [...form.sidebar.order],
    },
  } as unknown as ThemeUserPrefs;

  return prefs;
};

const COLOR_OVERRIDES = ["color.primary", "color.surface", "color.text"] as const;
const SHADOW_PRESETS = ["none", "default", "light", "heavy", "custom"] as const;
const SPACING_PRESETS = ["narrow", "default", "wide"] as const;
const TYPE_SCALE_PRESETS = ["small", "medium", "large"] as const;
const MOTION_PRESETS = ["none", "limited", "full"] as const;

const MIN_SIDEBAR_WIDTH = 50;
const MAX_SIDEBAR_WIDTH = 480; // ~50% on 960px width

const buildForm = (prefs: ThemeUserPrefs): PrefForm => ({
  theme: prefs.theme,
  mode: prefs.mode,
  overrides: { ...prefs.overrides },
  sidebar: {
    collapsed: prefs.sidebar.collapsed,
    width: prefs.sidebar.width,
    order: [...prefs.sidebar.order],
  },
});

const buildPayload = (form: PrefForm, baseline: PrefForm | null): Record<string, unknown> => {
  const payload: Record<string, unknown> = {};
  if (!baseline || form.theme !== baseline.theme) {
    payload.theme = form.theme;
  }
  if (!baseline || form.mode !== baseline.mode) {
    payload.mode = form.mode;
  }
  if (!baseline || !shallowEqual(form.overrides, baseline.overrides)) {
    payload.overrides = form.overrides;
  }
  if (
    !baseline ||
    baseline.sidebar.collapsed !== form.sidebar.collapsed ||
    baseline.sidebar.width !== form.sidebar.width ||
    !arrayEqual(baseline.sidebar.order, form.sidebar.order)
  ) {
    payload.sidebar = { ...form.sidebar };
  }

  return payload;
};

const shallowEqual = (a: Record<string, string>, b: Record<string, string>): boolean => {
  const aKeys = Object.keys(a);
  const bKeys = Object.keys(b);
  if (aKeys.length !== bKeys.length) return false;
  for (const key of aKeys) {
    if (a[key] !== b[key]) return false;
  }
  return true;
};

const arrayEqual = (a: unknown[], b: unknown[]): boolean => {
  if (a.length !== b.length) return false;
  return a.every((val, idx) => val === b[idx]);
};

async function parseJson<T>(res: Response): Promise<T | null> {
  try {
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

export default function ThemePreferences(): JSX.Element {
  const [manifest, setManifest] = useState<ThemeManifest>(() => getCachedThemeManifest());
  const [globalSettings, setGlobalSettings] = useState<ThemeSettings>(DEFAULT_THEME_SETTINGS);

  const [form, setForm] = useState<PrefForm>(buildForm(DEFAULT_USER_PREFS));
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [readOnly, setReadOnly] = useState(false);

  const etagRef = useRef<string | null>(null);
  const snapshotRef = useRef<PrefForm | null>(null);

  const effectiveGlobalTheme = useMemo(() => {
    const baseline = globalSettings.theme;
    return baseline.default;
  }, [globalSettings]);

  const manifestOptions = useMemo(() => [...manifest.themes, ...manifest.packs], [manifest]);

  const selectedThemeInfo = useMemo(() => {
    const slug = form.theme ?? effectiveGlobalTheme;
    return manifestOptions.find((item) => item.slug === slug);
  }, [form.theme, manifestOptions, effectiveGlobalTheme]);

  const allowedModes = selectedThemeInfo?.supports?.mode ?? ["light", "dark"];

  const allowOverride = globalSettings.theme.allow_user_override;
  const forceGlobal = globalSettings.theme.force_global;

  useEffect(() => {
    const unsubscribe = onThemeManifestChange((next) => {
      setManifest(next);
    });
    return unsubscribe;
  }, []);

  const applyPrefs = useCallback((prefs: ThemeUserPrefs) => {
    const nextForm = buildForm(prefs);
    snapshotRef.current = nextForm;
    setForm(nextForm);
    updateThemePrefs(prefs);
  }, []);

  const previewForm = useCallback((formSnapshot: PrefForm) => {
    updateThemePrefs(formToPrefs(formSnapshot));
  }, []);

  const loadAll = useCallback(async (options?: { preserveMessage?: boolean }) => {
    setLoading(true);
    if (!options?.preserveMessage) {
      setMessage(null);
    }

    try {
      const [manifestRes, globalRes, prefsRes] = await Promise.all([
        fetch("/api/settings/ui/themes", {
          method: "GET",
          credentials: "same-origin",
          headers: baseHeaders(),
        }),
        fetch("/api/settings/ui", {
          method: "GET",
          credentials: "same-origin",
          headers: baseHeaders(),
        }),
        fetch("/api/me/prefs/ui", {
          method: "GET",
          credentials: "same-origin",
          headers: baseHeaders(),
        }),
      ]);

      if (manifestRes.ok) {
        const manifestBody = await parseJson<ThemeManifest>(manifestRes);
        if (manifestBody && Array.isArray(manifestBody.themes)) {
          setManifest(manifestBody);
          updateThemeManifest(manifestBody);
        }
      }

      if (globalRes.ok) {
        const globalBody = await parseJson<{ config?: { ui?: ThemeSettings } }>(globalRes);
        if (globalBody?.config?.ui) {
          setGlobalSettings(globalBody.config.ui);
          updateThemeSettings(globalBody.config.ui);
        }
      }

      if (prefsRes.status === 403) {
        setReadOnly(true);
        setMessage("You do not have permission to manage UI preferences.");
        etagRef.current = null;
        applyPrefs(DEFAULT_USER_PREFS);
        return;
      }

      if (!prefsRes.ok) {
        setMessage("Failed to load preferences. Using defaults.");
        etagRef.current = null;
        applyPrefs(DEFAULT_USER_PREFS);
        return;
      }

      etagRef.current = prefsRes.headers.get("ETag");
      const prefsBody = await parseJson<UserPrefsResponse>(prefsRes);
      if (prefsBody?.prefs) {
        applyPrefs(prefsBody.prefs);
      } else {
        applyPrefs(DEFAULT_USER_PREFS);
      }

      setReadOnly(false);
    } catch {
      setMessage("Failed to load preferences. Using defaults.");
      etagRef.current = null;
      applyPrefs(DEFAULT_USER_PREFS);
      setGlobalSettings(DEFAULT_THEME_SETTINGS);
      updateThemeSettings(DEFAULT_THEME_SETTINGS);
    } finally {
      setLoading(false);
    }
  }, [applyPrefs]);

  useEffect(() => {
    void loadAll();
  }, [loadAll]);

  const onSelectTheme = (value: string) => {
    setForm((prev) => {
      const next = { ...prev, theme: value };
      previewForm(next);
      return next;
    });
  };

  const onSelectMode = (value: "light" | "dark" | null) => {
    setForm((prev) => {
      const next = { ...prev, mode: value };
      previewForm(next);
      return next;
    });
  };

  const onOverrideChange = (key: string, value: string) => {
    setForm((prev) => {
      const next = { ...prev, overrides: { ...prev.overrides, [key]: value } };
      previewForm(next);
      return next;
    });
  };

  const onOverridePresetChange = (key: string, value: string) => {
    setForm((prev) => {
      const next = { ...prev, overrides: { ...prev.overrides, [key]: value } };
      previewForm(next);
      return next;
    });
  };

  const onSidebarCollapsed = (value: boolean) => {
    setForm((prev) => ({ ...prev, sidebar: { ...prev.sidebar, collapsed: value } }));
  };

  const onSidebarWidth = (value: number) => {
    const clamped = Math.max(MIN_SIDEBAR_WIDTH, Math.min(MAX_SIDEBAR_WIDTH, value));
    setForm((prev) => ({ ...prev, sidebar: { ...prev.sidebar, width: clamped } }));
  };

  const resetToSaved = () => {
    if (snapshotRef.current) {
      const saved = snapshotRef.current;
      const next: PrefForm = {
        theme: saved.theme,
        mode: saved.mode,
        overrides: { ...saved.overrides },
        sidebar: {
          collapsed: saved.sidebar.collapsed,
          width: saved.sidebar.width,
          order: [...saved.sidebar.order],
        },
      };
      setForm(next);
      previewForm(next);
      setMessage("Reverted to last saved preferences.");
    }
  };

  const resetToGlobal = () => {
    const globalOverrides = globalSettings.theme.overrides;
    const next: PrefForm = {
      theme: allowOverride && !forceGlobal ? null : globalSettings.theme.default,
      mode: null,
      overrides: { ...globalOverrides },
      sidebar: {
        collapsed: DEFAULT_USER_PREFS.sidebar.collapsed,
        width: DEFAULT_USER_PREFS.sidebar.width,
        order: [...DEFAULT_USER_PREFS.sidebar.order],
      },
    };
    setForm(next);
    previewForm(next);
    setMessage("Preferences reset to global defaults.");
  };

  const persistForm = useCallback(
    async (formSnapshot: PrefForm, options?: { silent?: boolean }) => {
      if (readOnly) {
        if (!options?.silent) {
          setMessage("Read-only mode: preferences cannot be changed.");
        }
        return false;
      }

      const baseline = snapshotRef.current;
      const payload = buildPayload(formSnapshot, baseline);
      if (Object.keys(payload).length === 0) {
        if (!options?.silent) {
          setMessage("No changes to save.");
        }
        return true;
      }

      const etag = etagRef.current;
      if (!etag) {
        await loadAll({ preserveMessage: options?.silent });
        if (!options?.silent) {
          setMessage("Preferences version refreshed. Please retry.");
        }
        return false;
      }

      setSaving(true);
      if (!options?.silent) {
        setMessage(null);
      }

      try {
        const bodyPayload: Record<string, unknown> = { ...payload };
        if (forceGlobal || !allowOverride) {
          bodyPayload.theme = null;
        }
        if (!allowOverride) {
          bodyPayload.overrides = {};
        }

        const res = await fetch("/api/me/prefs/ui", {
          method: "PUT",
          credentials: "same-origin",
          headers: baseHeaders({
            "Content-Type": "application/json",
            "If-Match": etag,
          }),
          body: JSON.stringify(bodyPayload),
        });

        const body = await parseJson<SaveResponse>(res);

        if (res.status === 409) {
          const nextEtag = body?.current_etag ?? res.headers.get("ETag");
          if (nextEtag) {
            etagRef.current = nextEtag;
          }
          if (!options?.silent) {
            setMessage("Preferences changed elsewhere. Reloaded latest values.");
          }
          await loadAll({ preserveMessage: options?.silent ?? true });
          return false;
        }

        if (!res.ok) {
          throw new Error(`Save failed (HTTP ${res.status}).`);
        }

        const nextEtag = res.headers.get("ETag") ?? body?.etag ?? null;
        if (nextEtag) {
          etagRef.current = nextEtag;
        }

        if (body?.prefs) {
          applyPrefs(body.prefs);
        } else {
          snapshotRef.current = formSnapshot;
          setForm(formSnapshot);
          previewForm(formSnapshot);
        }

        if (!options?.silent) {
          const successMsg = typeof body?.message === "string" ? body.message : "Preferences saved.";
          setMessage(successMsg);
        }

        return true;
      } catch (err) {
        if (!options?.silent) {
          setMessage(err instanceof Error ? err.message : "Save failed.");
        }
        return false;
      } finally {
        setSaving(false);
      }
    },
    [readOnly, allowOverride, forceGlobal, loadAll, applyPrefs, previewForm]
  );

  const handleSave = async () => {
    await persistForm(form);
  };

  const disableThemeSelect = !allowOverride || forceGlobal || readOnly || saving || loading;
  const disableModeSelect = readOnly || saving || loading || allowedModes.length === 1;

  const selectedThemeDisplay = form.theme ?? effectiveGlobalTheme;

  const disabled = saving || loading || readOnly;

  return (
    <main id="profile-theme" className="container py-3" aria-label="theme-preferences">
      <h1 className="mb-3">Theme Preferences</h1>
      <section className="card">
        <div className="card-header d-flex justify-content-between align-items-center">
          <strong>Personal Theme</strong>
          <div className="d-flex gap-2">
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={() => void loadAll()}
              disabled={loading || saving}
            >
              Reload
            </button>
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={resetToSaved}
              disabled={disabled}
            >
              Reset to saved
            </button>
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={resetToGlobal}
              disabled={disabled}
            >
              Reset to global
            </button>
            <button
              type="button"
              className="btn btn-primary btn-sm"
              onClick={() => void handleSave()}
              disabled={disabled}
            >
              {saving ? "Saving…" : "Save"}
            </button>
          </div>
        </div>
        <div className="card-body vstack gap-4">
          {loading ? (
            <p>Loading preferences…</p>
          ) : (
            <>
              {message && (
                <div className="alert alert-info mb-0" role="status">
                  {message}
                </div>
              )}

              <fieldset className="vstack gap-3" disabled={disabled}>
                <div>
                  <label htmlFor="prefTheme" className="form-label fw-semibold">
                    Theme selection
                  </label>
                  <select
                    id="prefTheme"
                    className="form-select"
                    value={selectedThemeDisplay}
                    onChange={(event) => onSelectTheme(event.target.value)}
                    disabled={disableThemeSelect}
                  >
                    {manifestOptions.map((opt) => (
                      <option key={opt.slug} value={opt.slug}>
                        {opt.name} ({opt.source === "custom" ? "Custom" : "Bootswatch"})
                      </option>
                    ))}
                  </select>
                  {!allowOverride && (
                    <div className="form-text">
                      Theme overrides are disabled by your administrator.
                    </div>
                  )}
                  {forceGlobal && (
                    <div className="form-text">
                      Global theme is enforced. Light/dark mode preference still applies when supported.
                    </div>
                  )}
                </div>

                <fieldset>
                  <legend className="form-label fw-semibold">Mode</legend>
                  <div className="d-flex gap-3">
                    {["light", "dark"].map((mode) => (
                      <label key={mode} className="form-check">
                        <input
                          className="form-check-input"
                          type="radio"
                          name="themeMode"
                          value={mode}
                          checked={form.mode === mode}
                          disabled={disableModeSelect || !allowedModes.includes(mode as "light" | "dark")}
                          onChange={() => onSelectMode(mode as "light" | "dark")}
                        />
                        <span className="form-check-label text-capitalize">{mode}</span>
                      </label>
                    ))}
                    <label className="form-check">
                      <input
                        className="form-check-input"
                        type="radio"
                        name="themeMode"
                        value="system"
                        checked={form.mode === null}
                        disabled={disableModeSelect}
                        onChange={() => onSelectMode(null)}
                      />
                      <span className="form-check-label">Follow system</span>
                    </label>
                  </div>
                </fieldset>

                <div>
                  <h2 className="h5 mb-3">Design tokens</h2>
                  <div className="row g-3">
                    {COLOR_OVERRIDES.map((key) => (
                      <div key={key} className="col-sm-4">
                        <label htmlFor={`pref-${key}`} className="form-label text-capitalize">
                          {key.replace("color.", "")} color
                        </label>
                        <input
                          id={`pref-${key}`}
                          type="color"
                          className="form-control form-control-color"
                          value={form.overrides[key] ?? "#000000"}
                          onChange={(event) => onOverrideChange(key, event.target.value)}
                        />
                      </div>
                    ))}
                  </div>

                  <div className="row g-3 mt-1">
                    <div className="col-sm-3">
                      <label htmlFor="prefShadow" className="form-label">
                        Shadow preset
                      </label>
                      <select
                        id="prefShadow"
                        className="form-select"
                        value={form.overrides.shadow ?? "default"}
                        onChange={(event) => onOverridePresetChange("shadow", event.target.value)}
                      >
                        {SHADOW_PRESETS.map((preset) => (
                          <option key={preset} value={preset}>
                            {preset}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="col-sm-3">
                      <label htmlFor="prefSpacing" className="form-label">
                        Spacing scale
                      </label>
                      <select
                        id="prefSpacing"
                        className="form-select"
                        value={form.overrides.spacing ?? "default"}
                        onChange={(event) => onOverridePresetChange("spacing", event.target.value)}
                      >
                        {SPACING_PRESETS.map((preset) => (
                          <option key={preset} value={preset}>
                            {preset}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="col-sm-3">
                      <label htmlFor="prefTypeScale" className="form-label">
                        Type scale
                      </label>
                      <select
                        id="prefTypeScale"
                        className="form-select"
                        value={form.overrides.typeScale ?? "medium"}
                        onChange={(event) => onOverridePresetChange("typeScale", event.target.value)}
                      >
                        {TYPE_SCALE_PRESETS.map((preset) => (
                          <option key={preset} value={preset}>
                            {preset}
                          </option>
                        ))}
                      </select>
                    </div>
                    <div className="col-sm-3">
                      <label htmlFor="prefMotion" className="form-label">
                        Motion
                      </label>
                      <select
                        id="prefMotion"
                        className="form-select"
                        value={form.overrides.motion ?? "full"}
                        onChange={(event) => onOverridePresetChange("motion", event.target.value)}
                      >
                        {MOTION_PRESETS.map((preset) => (
                          <option key={preset} value={preset}>
                            {preset}
                          </option>
                        ))}
                      </select>
                    </div>
                  </div>
                </div>

                <div>
                  <h2 className="h5 mb-3">Sidebar</h2>
                  <div className="form-check mb-2">
                    <input
                      id="prefSidebarCollapsed"
                      type="checkbox"
                      className="form-check-input"
                      checked={form.sidebar.collapsed}
                      onChange={(event) => onSidebarCollapsed(event.target.checked)}
                    />
                    <label htmlFor="prefSidebarCollapsed" className="form-check-label">
                      Collapse sidebar by default
                    </label>
                  </div>
                  <label htmlFor="prefSidebarWidth" className="form-label">
                    Sidebar width (px)
                  </label>
                  <input
                    id="prefSidebarWidth"
                    type="range"
                    className="form-range"
                    min={MIN_SIDEBAR_WIDTH}
                    max={MAX_SIDEBAR_WIDTH}
                    value={form.sidebar.width}
                    onChange={(event) => onSidebarWidth(Number(event.target.value))}
                  />
                  <div className="form-text">Current width: {form.sidebar.width}px (min 50px, max ~50% viewport).</div>
                  <div className="alert alert-secondary mt-3" role="note">
                    Sidebar module ordering coming soon.
                  </div>
                </div>
              </fieldset>
            </>
          )}
        </div>
      </section>
    </main>
  );
}
