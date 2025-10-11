import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { baseHeaders } from "../../lib/api";
import {
  DEFAULT_THEME_MANIFEST,
  DEFAULT_THEME_SETTINGS,
  type ThemeManifest,
  type ThemeSettings,
} from "./themeData";
import { updateThemeManifest, updateThemeSettings } from "../../theme/themeManager";

type FormState = {
  theme: string;
  allowOverride: boolean;
  forceGlobal: boolean;
  overrides: Record<string, string>;
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

const COLOR_OVERRIDES: Array<{ key: string; label: string }> = [
  { key: "color.primary", label: "Primary color" },
  { key: "color.surface", label: "Surface color" },
  { key: "color.text", label: "Text color" },
];

const SHADOW_PRESETS = ["none", "default", "light", "heavy", "custom"] as const;
const SPACING_PRESETS = ["narrow", "default", "wide"] as const;
const TYPE_SCALE_PRESETS = ["small", "medium", "large"] as const;
const MOTION_PRESETS = ["none", "limited", "full"] as const;

function buildInitialForm(settings: ThemeSettings): FormState {
  return {
    theme: settings.theme.default,
    allowOverride: settings.theme.allow_user_override,
    forceGlobal: settings.theme.force_global,
    overrides: { ...settings.theme.overrides },
  };
}

function hasChanges(form: FormState, baseline: FormState | null): boolean {
  if (!baseline) return true;
  if (form.theme !== baseline.theme) return true;
  if (form.allowOverride !== baseline.allowOverride) return true;
  if (form.forceGlobal !== baseline.forceGlobal) return true;
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
  const [message, setMessage] = useState<string | null>(null);
  const [readOnly, setReadOnly] = useState(false);

  const [manifest, setManifest] = useState<ThemeManifest>(DEFAULT_THEME_MANIFEST);
  const [form, setForm] = useState<FormState>(buildInitialForm(DEFAULT_THEME_SETTINGS));

  const etagRef = useRef<string | null>(null);
  const snapshotRef = useRef<FormState | null>(buildInitialForm(DEFAULT_THEME_SETTINGS));

  const themeOptions = useMemo(() => {
    const base = [...manifest.themes, ...manifest.packs];
    return base.map((theme) => ({
      slug: theme.slug,
      name: theme.name,
      source: theme.source,
    }));
  }, [manifest]);

  const applySettings = useCallback((settings: ThemeSettings) => {
    const nextForm = buildInitialForm(settings);
    snapshotRef.current = nextForm;
    setForm(nextForm);
    updateThemeSettings(settings);
  }, []);

  const loadManifest = useCallback(async () => {
    try {
      const res = await fetch("/api/settings/ui/themes", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });
      if (!res.ok) {
        setMessage("Theme manifest unavailable; using bundled defaults.");
        return;
      }
      const body = await parseJson<ThemeManifest>(res);
      if (body && Array.isArray(body.themes)) {
        setManifest(body);
        updateThemeManifest(body);
      }
    } catch {
      setMessage("Theme manifest unavailable; using bundled defaults.");
    }
  }, []);

  const loadSettings = useCallback(async (options?: { preserveMessage?: boolean }) => {
    setLoading(true);
    if (!options?.preserveMessage) {
      setMessage(null);
    }
    try {
      await loadManifest();

      const res = await fetch("/api/settings/ui", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });

      if (res.status === 403) {
        setReadOnly(true);
        setMessage("You do not have permission to adjust theme settings.");
        etagRef.current = null;
        snapshotRef.current = buildInitialForm(DEFAULT_THEME_SETTINGS);
        setForm(buildInitialForm(DEFAULT_THEME_SETTINGS));
        updateThemeSettings(DEFAULT_THEME_SETTINGS);
        return;
      }

      if (!res.ok) {
        setMessage("Failed to load theme settings. Using defaults.");
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
      setMessage("Failed to load theme settings. Using defaults.");
      etagRef.current = null;
      snapshotRef.current = buildInitialForm(DEFAULT_THEME_SETTINGS);
      setForm(buildInitialForm(DEFAULT_THEME_SETTINGS));
      updateThemeSettings(DEFAULT_THEME_SETTINGS);
    } finally {
      setLoading(false);
    }
  }, [applySettings, loadManifest]);

  useEffect(() => {
    void loadSettings();
  }, [loadSettings]);

  const onChangeTheme = (value: string) => {
    setForm((prev) => ({ ...prev, theme: value }));
  };

  const onToggleAllow = (value: boolean) => {
    setForm((prev) => ({ ...prev, allowOverride: value }));
  };

  const onToggleForce = (value: boolean) => {
    setForm((prev) => ({ ...prev, forceGlobal: value }));
  };

  const onColorChange = (key: string, value: string) => {
    setForm((prev) => ({
      ...prev,
      overrides: { ...prev.overrides, [key]: value },
    }));
  };

  const onPresetChange = (key: string, value: string) => {
    setForm((prev) => ({
      ...prev,
      overrides: { ...prev.overrides, [key]: value },
    }));
  };

  const resetToBaseline = () => {
    const snapshot = snapshotRef.current ?? buildInitialForm(DEFAULT_THEME_SETTINGS);
    setForm(snapshot);
    setMessage("Reverted to last saved values.");
  };

  const handleSave = async () => {
    if (readOnly) {
      setMessage("Read-only mode: theme settings cannot be changed.");
      return;
    }
    const snapshot = snapshotRef.current;
    if (!hasChanges(form, snapshot)) {
      setMessage("No changes to save.");
      return;
    }
    const etag = etagRef.current;
    if (!etag) {
      await loadSettings();
      setMessage("Settings version refreshed. Please retry.");
      return;
    }

    setSaving(true);
    setMessage(null);

    try {
      const payload = {
        ui: {
          theme: {
            default: form.theme,
            allow_user_override: form.allowOverride,
            force_global: form.forceGlobal,
            overrides: form.overrides,
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
        setMessage("Settings changed elsewhere. Reloaded latest values.");
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
      setMessage(msg);
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Save failed.");
    } finally {
      setSaving(false);
    }
  };

  const disabled = loading || saving || readOnly;

  return (
    <section className="card mb-4" aria-label="theme-configurator">
      <div className="card-header d-flex justify-content-between align-items-center">
        <strong>Theming</strong>
        <div className="d-flex gap-2">
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
            {message && (
              <div className="alert alert-info mb-0" role="status">
                {message}
              </div>
            )}
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
                  Users can select from available themes when overrides are allowed.
                </div>
              </div>

              <div className="form-check">
                <input
                  id="allowOverride"
                  type="checkbox"
                  className="form-check-input"
                  checked={form.allowOverride}
                  onChange={(event) => onToggleAllow(event.target.checked)}
                />
                <label htmlFor="allowOverride" className="form-check-label">
                  Allow user theme override
                </label>
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

              <div>
                <h2 className="h5 mb-3">Design tokens</h2>
                <div className="row g-3">
                  {COLOR_OVERRIDES.map(({ key, label }) => (
                    <div key={key} className="col-sm-4">
                      <label htmlFor={key} className="form-label">
                        {label}
                      </label>
                      <input
                        id={key}
                        className="form-control form-control-color"
                        type="color"
                        value={form.overrides[key] ?? "#000000"}
                        onChange={(event) => onColorChange(key, event.target.value)}
                        title={label}
                      />
                    </div>
                  ))}
                </div>

                <div className="row g-3 mt-1">
                  <div className="col-sm-3">
                    <label htmlFor="shadowPreset" className="form-label">
                      Shadow preset
                    </label>
                    <select
                      id="shadowPreset"
                      className="form-select"
                      value={form.overrides.shadow ?? "default"}
                      onChange={(event) => onPresetChange("shadow", event.target.value)}
                    >
                      {SHADOW_PRESETS.map((preset) => (
                        <option key={preset} value={preset}>
                          {preset}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="col-sm-3">
                    <label htmlFor="spacingPreset" className="form-label">
                      Spacing scale
                    </label>
                    <select
                      id="spacingPreset"
                      className="form-select"
                      value={form.overrides.spacing ?? "default"}
                      onChange={(event) => onPresetChange("spacing", event.target.value)}
                    >
                      {SPACING_PRESETS.map((preset) => (
                        <option key={preset} value={preset}>
                          {preset}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="col-sm-3">
                    <label htmlFor="typeScalePreset" className="form-label">
                      Type scale
                    </label>
                    <select
                      id="typeScalePreset"
                      className="form-select"
                      value={form.overrides.typeScale ?? "medium"}
                      onChange={(event) => onPresetChange("typeScale", event.target.value)}
                    >
                      {TYPE_SCALE_PRESETS.map((preset) => (
                        <option key={preset} value={preset}>
                          {preset}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="col-sm-3">
                    <label htmlFor="motionPreset" className="form-label">
                      Motion
                    </label>
                    <select
                      id="motionPreset"
                      className="form-select"
                      value={form.overrides.motion ?? "full"}
                      onChange={(event) => onPresetChange("motion", event.target.value)}
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
            </fieldset>
          </>
        )}
      </div>
    </section>
  );
}
