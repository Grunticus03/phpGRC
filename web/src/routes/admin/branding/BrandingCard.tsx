import { useCallback, useEffect, useRef, useState, type CSSProperties } from "react";
import { baseHeaders } from "../../../lib/api";
import { updateThemeSettings } from "../../../theme/themeManager";
import { DEFAULT_THEME_SETTINGS, type ThemeSettings } from "../themeData";

type Mutable<T> = T extends object ? { -readonly [K in keyof T]: Mutable<T[K]> } : T;

type BrandingConfig = Mutable<ThemeSettings["brand"]>;

type BrandingResponse = {
  ok?: boolean;
  config?: { ui?: ThemeSettings };
  etag?: string;
};

type BrandAsset = {
  id: string;
  profile_id: string;
  kind: "primary_logo" | "secondary_logo" | "header_logo" | "footer_logo" | "favicon";
  name: string;
  mime: string;
  size_bytes: number;
  sha256: string;
  uploaded_by: string | null;
  created_at: string;
  url?: string;
};

type BrandProfile = {
  id: string;
  name: string;
  is_default: boolean;
  is_active: boolean;
  is_locked: boolean;
  brand: BrandingConfig;
  created_at: string | null;
  updated_at: string | null;
};

type AssetListResponse = {
  ok?: boolean;
  assets?: BrandAsset[];
};

type UploadResponse = {
  ok?: boolean;
  message?: string;
  note?: string;
  asset?: BrandAsset;
};

type BrandProfilesResponse = {
  ok?: boolean;
  profiles?: BrandProfile[];
};

type BrandProfileResponse = {
  ok?: boolean;
  profile?: BrandProfile;
};

const MAX_FILE_SIZE = 5 * 1024 * 1024;
const ALLOWED_TYPES = ["image/png", "image/jpeg", "image/webp", "image/svg+xml"];

const assetLabel: Record<BrandAsset["kind"], string> = {
  primary_logo: "Primary logo",
  secondary_logo: "Secondary logo",
  header_logo: "Header logo",
  footer_logo: "Footer logo",
  favicon: "Favicon",
};

const assetDownloadUrl = (assetId: string): string =>
  `/api/settings/ui/brand-assets/${encodeURIComponent(assetId)}/download`;

const normalizeSettings = (incoming?: ThemeSettings | null): ThemeSettings => ({
  ...DEFAULT_THEME_SETTINGS,
  ...(incoming ?? {}),
  theme: {
    ...DEFAULT_THEME_SETTINGS.theme,
    ...((incoming?.theme ?? DEFAULT_THEME_SETTINGS.theme) as ThemeSettings["theme"]),
  },
  nav: {
    ...DEFAULT_THEME_SETTINGS.nav,
    ...((incoming?.nav ?? DEFAULT_THEME_SETTINGS.nav) as ThemeSettings["nav"]),
  },
  brand: {
    ...DEFAULT_THEME_SETTINGS.brand,
    ...((incoming?.brand ?? DEFAULT_THEME_SETTINGS.brand) as ThemeSettings["brand"]),
  },
});

const createDefaultBrandConfig = (): BrandingConfig => ({
  ...DEFAULT_THEME_SETTINGS.brand,
});

async function parseJson<T>(res: Response): Promise<T | null> {
  try {
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

export default function BrandingCard(): JSX.Element {
  const [brandConfig, setBrandConfig] = useState<BrandingConfig>(createDefaultBrandConfig());
  const [assets, setAssets] = useState<BrandAsset[]>([]);
  const [profiles, setProfiles] = useState<BrandProfile[]>([]);
  const [selectedProfileId, setSelectedProfileId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [profileSaving, setProfileSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [uploadMessage, setUploadMessage] = useState<string | null>(null);
  const [readOnly, setReadOnly] = useState(false);
  const [isCreatingProfile, setIsCreatingProfile] = useState(false);
  const [newProfileName, setNewProfileName] = useState("");

  const etagRef = useRef<string | null>(null);
  const baselineRef = useRef<BrandingConfig | null>(null);
  const settingsRef = useRef<ThemeSettings>(DEFAULT_THEME_SETTINGS);
  const selectedProfileRef = useRef<string | null>(null);

  useEffect(() => {
    selectedProfileRef.current = selectedProfileId;
  }, [selectedProfileId]);

  const fetchAssets = useCallback(async (profileId: string): Promise<BrandAsset[]> => {
    if (!profileId) return [];
    try {
      const res = await fetch(`/api/settings/ui/brand-assets?profile_id=${encodeURIComponent(profileId)}`, {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });
      if (!res.ok) {
        return [];
      }
      const assetsBody = await parseJson<AssetListResponse>(res);
      return Array.isArray(assetsBody?.assets)
        ? assetsBody.assets.map((asset) => ({
            ...asset,
            url: assetDownloadUrl(asset.id),
          }))
        : [];
    } catch {
      return [];
    }
  }, []);

  const fetchProfiles = useCallback(async (): Promise<BrandProfile[]> => {
    try {
      const res = await fetch("/api/settings/ui/brand-profiles", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });
      if (!res.ok) {
        return [];
      }
      const body = await parseJson<BrandProfilesResponse>(res);
      return Array.isArray(body?.profiles) ? body.profiles : [];
    } catch {
      return [];
    }
  }, []);

  const loadBranding = useCallback(
    async (options?: { preserveMessage?: boolean; profileId?: string }) => {
      setLoading(true);
      if (!options?.preserveMessage) {
        setMessage(null);
        setUploadMessage(null);
      }

      try {
        const settingsRes = await fetch("/api/settings/ui", {
          method: "GET",
          credentials: "same-origin",
          headers: baseHeaders(),
        });

        if (settingsRes.status === 403) {
          setReadOnly(true);
          setMessage("You do not have permission to update branding.");
          etagRef.current = null;
          setProfiles([]);
          setSelectedProfileId(null);
          const defaults = createDefaultBrandConfig();
          setBrandConfig(defaults);
          baselineRef.current = defaults;
          setAssets([]);
          return;
        }

        let uiSettings: ThemeSettings | null = null;
        if (settingsRes.ok) {
          etagRef.current = settingsRes.headers.get("ETag");
          const body = await parseJson<BrandingResponse>(settingsRes);
          uiSettings = body?.config?.ui ?? null;
        } else {
          setMessage("Failed to load branding settings. Using defaults.");
        }

        const profileList = await fetchProfiles();
        setProfiles(profileList);

        if (uiSettings) {
          const normalized = normalizeSettings(uiSettings);
          settingsRef.current = normalized;
          updateThemeSettings(normalized);
        } else {
          const normalized = normalizeSettings(null);
          settingsRef.current = normalized;
          updateThemeSettings(normalized);
        }

        const preferredProfileId = options?.profileId ?? selectedProfileRef.current;
        let nextSelectedId: string | null = preferredProfileId ?? null;
        if (!nextSelectedId || !profileList.some((profile) => profile.id === nextSelectedId)) {
          const activeProfile = profileList.find((profile) => profile.is_active);
          nextSelectedId = activeProfile?.id ?? profileList[0]?.id ?? null;
        }

        setSelectedProfileId(nextSelectedId);

        const selectedProfile = profileList.find((profile) => profile.id === nextSelectedId) ?? null;
        if (selectedProfile) {
          const config: BrandingConfig = { ...selectedProfile.brand };
          setBrandConfig(config);
          baselineRef.current = { ...config };
          const assetList = await fetchAssets(selectedProfile.id);
          setAssets(assetList);
        } else {
          const defaults = createDefaultBrandConfig();
          setBrandConfig(defaults);
          baselineRef.current = defaults;
          setAssets([]);
        }

        setReadOnly(false);
      } catch {
        setMessage("Failed to load branding settings. Using defaults.");
        etagRef.current = null;
        const defaults = createDefaultBrandConfig();
        const normalized = normalizeSettings(null);
        settingsRef.current = normalized;
        updateThemeSettings(normalized);
        setBrandConfig(defaults);
        baselineRef.current = defaults;
        setProfiles([]);
        setSelectedProfileId(null);
        setAssets([]);
      } finally {
        setLoading(false);
      }
    },
    [fetchAssets, fetchProfiles]
  );

  useEffect(() => {
    void loadBranding();
  }, [loadBranding]);

  const selectedProfile = profiles.find((profile) => profile.id === selectedProfileId) ?? null;
  const profileLocked = selectedProfile?.is_locked ?? false;

  const handleSelectProfile = (profileId: string) => {
    if (profileId === selectedProfileId) {
      return;
    }

    if (profileId === "") {
      setSelectedProfileId(null);
      const defaults = createDefaultBrandConfig();
      setBrandConfig(defaults);
      baselineRef.current = defaults;
      setAssets([]);
      return;
    }

    setSelectedProfileId(profileId);
    setUploadMessage(null);

    const profile = profiles.find((item) => item.id === profileId) ?? null;
    if (profile) {
      const config: BrandingConfig = { ...profile.brand };
      setBrandConfig(config);
      baselineRef.current = { ...config };
      previewBrand(config);
      if (profile.is_locked) {
        setMessage("Default profile is locked. Create a new profile to customize branding.");
      }
      setAssets([]);
      void fetchAssets(profile.id).then((list) => setAssets(list));
    } else {
      const defaults = createDefaultBrandConfig();
      setBrandConfig(defaults);
      baselineRef.current = defaults;
      previewBrand(defaults);
      setAssets([]);
    }
  };

  const handleCreateProfile = async () => {
    const name = newProfileName.trim();
    if (name === "") {
      setMessage("Profile name is required.");
      return;
    }

    setProfileSaving(true);
    try {
      const res = await fetch("/api/settings/ui/brand-profiles", {
        method: "POST",
        credentials: "same-origin",
        headers: baseHeaders({ "Content-Type": "application/json" }),
        body: JSON.stringify({ name }),
      });

      if (!res.ok) {
        const body = await parseJson<Record<string, unknown>>(res);
        const errMsg = typeof body?.message === "string" ? body.message : "Failed to create profile.";
        throw new Error(errMsg);
      }

      const body = await parseJson<BrandProfileResponse>(res);
      if (!body?.profile) {
        throw new Error("Profile response missing.");
      }

      setIsCreatingProfile(false);
      setNewProfileName("");
      setMessage(`Profile "${body.profile.name}" created. Configure and save to apply.`);
      await loadBranding({ preserveMessage: true, profileId: body.profile.id });
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to create profile.");
    } finally {
      setProfileSaving(false);
    }
  };

  const handleActivateProfile = async (profileId: string) => {
    setProfileSaving(true);
    try {
      const res = await fetch(`/api/settings/ui/brand-profiles/${encodeURIComponent(profileId)}/activate`, {
        method: "POST",
        credentials: "same-origin",
        headers: baseHeaders({ "Content-Type": "application/json" }),
      });

      if (!res.ok) {
        const body = await parseJson<Record<string, unknown>>(res);
        const errMsg = typeof body?.message === "string" ? body.message : "Failed to activate profile.";
        throw new Error(errMsg);
      }

      setMessage("Profile activated.");
      await loadBranding({ preserveMessage: true, profileId });
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Failed to activate profile.");
    } finally {
      setProfileSaving(false);
    }
  };

  const previewBrand = (config: BrandingConfig) => {
    const base = settingsRef.current;
    const draft: ThemeSettings = {
      ...base,
      brand: { ...config },
    };
    updateThemeSettings(draft);
  };

  const updateField = (key: keyof BrandingConfig, value: unknown) => {
    setBrandConfig((prev) => {
      const next = { ...prev, [key]: value } as BrandingConfig;
      previewBrand(next);
      return next;
    });
  };

  const hasChanges = (): boolean => {
    const baseline = baselineRef.current;
    if (!baseline) return true;
    return (
      brandConfig.title_text !== baseline.title_text ||
      brandConfig.favicon_asset_id !== baseline.favicon_asset_id ||
      brandConfig.primary_logo_asset_id !== baseline.primary_logo_asset_id ||
      brandConfig.secondary_logo_asset_id !== baseline.secondary_logo_asset_id ||
      brandConfig.header_logo_asset_id !== baseline.header_logo_asset_id ||
      brandConfig.footer_logo_asset_id !== baseline.footer_logo_asset_id ||
      brandConfig.footer_logo_disabled !== baseline.footer_logo_disabled
    );
  };

  const handleSave = async (): Promise<void> => {
    if (readOnly) {
      setMessage("Branding is read-only for your account.");
      return;
    }
    if (!selectedProfile) {
      setMessage("Select a branding profile before saving.");
      return;
    }
    if (selectedProfile.is_locked) {
      setMessage("Default branding profile cannot be modified.");
      return;
    }
    if (!hasChanges()) {
      setMessage("No branding changes to save.");
      return;
    }

    const etag = etagRef.current;
    if (!etag) {
      await loadBranding();
      setMessage("Settings version refreshed. Please retry.");
      return;
    }

    setSaving(true);
    setMessage(null);

    try {
      const payload = {
        ui: {
          brand: {
            ...brandConfig,
            profile_id: selectedProfile.id,
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

      const body = await parseJson<BrandingResponse>(res);

      if (res.status === 409) {
        const nextEtag = body?.etag ?? res.headers.get("ETag");
        if (nextEtag) etagRef.current = nextEtag;
        setMessage("Branding changed elsewhere. Reloaded latest values.");
        await loadBranding({ preserveMessage: true, profileId: selectedProfile.id });
        return;
      }

      if (!res.ok) {
        throw new Error(`Save failed (HTTP ${res.status}).`);
      }

      const nextEtag = res.headers.get("ETag") ?? body?.etag ?? null;
      if (nextEtag) etagRef.current = nextEtag;

      if (body?.config?.ui) {
        const normalized = normalizeSettings(body.config.ui);
        settingsRef.current = normalized;
        updateThemeSettings(normalized);
        const updated: BrandingConfig = { ...normalized.brand };
        setBrandConfig(updated);
        baselineRef.current = { ...updated };
      } else {
        baselineRef.current = { ...brandConfig };
      }

      setMessage(`Branding saved for "${selectedProfile.name}".`);
      await loadBranding({ preserveMessage: true, profileId: selectedProfile.id });
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Save failed.");
    } finally {
      setSaving(false);
    }
  };

  const handleUpload = async (kind: BrandAsset["kind"], file: File): Promise<void> => {
    if (!selectedProfile) {
      setUploadMessage("Select a branding profile before uploading.");
      return;
    }
    if (selectedProfile.is_locked) {
      setUploadMessage("Default branding profile does not accept uploads.");
      return;
    }
    if (!ALLOWED_TYPES.includes(file.type)) {
      setUploadMessage(`Unsupported file type: ${file.type || "unknown"}.`);
      return;
    }
    if (file.size > MAX_FILE_SIZE) {
      setUploadMessage("File exceeds 5 MB limit.");
      return;
    }

    const formData = new FormData();
    formData.append("kind", kind);
    formData.append("profile_id", selectedProfile.id);
    formData.append("file", file);

    try {
      const res = await fetch("/api/settings/ui/brand-assets", {
        method: "POST",
        credentials: "same-origin",
        headers: baseHeaders(),
        body: formData,
      });

      const body = await parseJson<UploadResponse>(res);
      if (!res.ok || !body?.asset) {
        const errMsg = typeof body?.message === "string" ? body.message : "Upload failed.";
        setUploadMessage(errMsg);
        return;
      }

      const mutableAsset: BrandAsset = {
        ...body.asset,
        url: assetDownloadUrl(body.asset.id),
      };
      setAssets((prev) => [mutableAsset, ...prev.filter((asset) => asset.id !== mutableAsset.id)]);

      switch (kind) {
        case "primary_logo":
          updateField("primary_logo_asset_id", body.asset.id);
          break;
        case "secondary_logo":
          updateField("secondary_logo_asset_id", body.asset.id);
          break;
        case "header_logo":
          updateField("header_logo_asset_id", body.asset.id);
          break;
        case "footer_logo":
          updateField("footer_logo_asset_id", body.asset.id);
          break;
        case "favicon":
          updateField("favicon_asset_id", body.asset.id);
          break;
      }

      setUploadMessage("Upload successful.");
      void fetchAssets(selectedProfile.id).then((list) => setAssets(list));
    } catch {
      setUploadMessage("Upload failed.");
    }
  };

  const handleDeleteAsset = async (assetId: string): Promise<void> => {
    if (!window.confirm("Delete this asset? This cannot be undone.")) return;
    if (!selectedProfile || selectedProfile.is_locked) {
      setUploadMessage("Cannot modify assets for this profile.");
      return;
    }
    try {
      const res = await fetch(`/api/settings/ui/brand-assets/${encodeURIComponent(assetId)}`, {
        method: "DELETE",
        credentials: "same-origin",
        headers: baseHeaders(),
      });
      if (!res.ok) {
        throw new Error(`Delete failed (${res.status})`);
      }
      setAssets((prev) => prev.filter((asset) => asset.id !== assetId));
      let cleared = false;
      const nextConfig: BrandingConfig = { ...brandConfig };
      ([
        "favicon",
        "primary_logo",
        "secondary_logo",
        "header_logo",
        "footer_logo",
      ] as const).forEach((kind) => {
        const key = `${kind}_asset_id` as keyof BrandingConfig;
        if (nextConfig[key] === assetId) {
          (nextConfig as Record<string, unknown>)[key] = null;
          cleared = true;
        }
      });
      if (cleared) {
        setBrandConfig(nextConfig);
        previewBrand(nextConfig);
        setMessage("Asset deleted and references cleared. Save to apply.");
      }
    } catch (err) {
      setUploadMessage(err instanceof Error ? err.message : "Delete failed.");
    }
  };

  const disabled = loading || saving || readOnly || profileLocked || profileSaving;

  const findAssetById = (id: string | null): BrandAsset | null => {
    if (!id) return null;
    return assets.find((asset) => asset.id === id) ?? null;
  };

  return (
    <section className="card mb-4" aria-label="branding-card">
      <div className="card-header d-flex justify-content-between align-items-center">
        <strong>Branding</strong>
        <div className="d-flex gap-2">
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => void loadBranding()}
            disabled={loading || saving || profileSaving}
            title="Fetch the latest branding settings from the server"
          >
            Fetch latest
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => {
              const saved = baselineRef.current;
              if (saved) {
                const next = { ...saved };
                setBrandConfig(next);
                previewBrand(next);
                setMessage("Reverted to last saved branding.");
              }
            }}
            disabled={disabled}
            title="Undo unsaved changes"
          >
            Undo changes
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
          <p>Loading branding settings…</p>
        ) : (
          <>
            {message && (
              <div className="alert alert-info mb-0" role="status">
                {message}
              </div>
            )}
            {uploadMessage && (
              <div className="alert alert-secondary mb-0" role="note">
                {uploadMessage}
              </div>
            )}

            <div className="border rounded p-3 bg-body-tertiary">
              <div className="row g-3 align-items-end">
                <div className="col-lg">
                  <label htmlFor="brandingProfileSelect" className="form-label fw-semibold mb-1">
                    Branding profile
                  </label>
                  <div className="d-flex flex-wrap gap-2 align-items-center">
                    <select
                      id="brandingProfileSelect"
                      className="form-select form-select-sm"
                      value={selectedProfileId ?? ""}
                      onChange={(event) => handleSelectProfile(event.target.value)}
                      disabled={profiles.length === 0 || loading || profileSaving || readOnly}
                    >
                      {profiles.length === 0 ? (
                        <option value="">No profiles available</option>
                      ) : (
                        <>
                          {selectedProfileId === null && <option value="">Select profile…</option>}
                          {profiles.map((profile) => (
                            <option key={profile.id} value={profile.id}>
                              {profile.name}
                              {profile.is_default ? " (Default)" : ""}
                              {profile.is_active ? " (Active)" : ""}
                            </option>
                          ))}
                        </>
                      )}
                    </select>
                    {selectedProfile && !selectedProfile.is_active && !readOnly && (
                      <button
                        type="button"
                        className="btn btn-outline-primary btn-sm"
                        onClick={() => void handleActivateProfile(selectedProfile.id)}
                        disabled={profileSaving}
                      >
                        {profileSaving ? "Activating…" : "Set active"}
                      </button>
                    )}
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm"
                      onClick={() => {
                        setIsCreatingProfile((value) => !value);
                        setNewProfileName("");
                      }}
                      disabled={profileSaving || readOnly}
                    >
                      {isCreatingProfile ? "Cancel" : "New profile"}
                    </button>
                  </div>
                </div>
                {isCreatingProfile && !readOnly && (
                  <div className="col-lg-4">
                    <label htmlFor="newProfileName" className="form-label fw-semibold mb-1">
                      Create new profile
                    </label>
                    <div className="d-flex gap-2">
                      <input
                        id="newProfileName"
                        type="text"
                        className="form-control form-control-sm"
                        placeholder="Profile name"
                        value={newProfileName}
                        maxLength={120}
                        onChange={(event) => setNewProfileName(event.target.value)}
                        disabled={profileSaving}
                      />
                      <button
                        type="button"
                        className="btn btn-primary btn-sm"
                        onClick={() => void handleCreateProfile()}
                        disabled={profileSaving}
                      >
                        {profileSaving ? "Creating…" : "Create"}
                      </button>
                    </div>
                  </div>
                )}
                {selectedProfile?.is_active && (
                  <div className="col-lg-auto">
                    <span className="badge text-bg-success">Active</span>
                  </div>
                )}
              </div>
              {profileLocked && (
                <div className="alert alert-warning mt-3 mb-0" role="note">
                  Default profile is read-only. Create a new profile to customize branding.
                </div>
              )}
            </div>

            <fieldset className="vstack gap-3" disabled={disabled}>
              <div>
                <label htmlFor="brandTitle" className="form-label fw-semibold">
                  Title text
                </label>
                <input
                  id="brandTitle"
                  type="text"
                  className="form-control"
                  value={brandConfig.title_text}
                  maxLength={120}
                  onChange={(event) => updateField("title_text", event.target.value)}
                />
                <div className="form-text">Default: phpGRC — &lt;module&gt;</div>
              </div>

              <BrandAssetSection
                label="Primary logo"
                description="Displayed in header and as fallback for other locations."
                kind="primary_logo"
                asset={findAssetById(brandConfig.primary_logo_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.primary_logo_asset_id !== null}
                onUpload={handleUpload}
                onSelect={(assetId) => updateField("primary_logo_asset_id", assetId)}
                onClear={() => updateField("primary_logo_asset_id", null)}
                onDelete={handleDeleteAsset}
                disabled={disabled}
              />

              <BrandAssetSection
                label="Secondary logo"
                description="Used when the primary logo is not suitable; falls back to primary otherwise."
                kind="secondary_logo"
                asset={findAssetById(brandConfig.secondary_logo_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.secondary_logo_asset_id !== null}
                onUpload={handleUpload}
                onSelect={(assetId) => updateField("secondary_logo_asset_id", assetId)}
                onClear={() => updateField("secondary_logo_asset_id", null)}
                onDelete={handleDeleteAsset}
                disabled={disabled}
              />

              <BrandAssetSection
                label="Header logo"
                description="Optional specialized logo for application header."
                kind="header_logo"
                asset={findAssetById(brandConfig.header_logo_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.header_logo_asset_id !== null}
                onUpload={handleUpload}
                onSelect={(assetId) => updateField("header_logo_asset_id", assetId)}
                onClear={() => updateField("header_logo_asset_id", null)}
                onDelete={handleDeleteAsset}
                disabled={disabled}
              />

              <BrandAssetSection
                label="Footer logo"
                description="Displayed in footer; falls back to primary when absent."
                kind="footer_logo"
                asset={findAssetById(brandConfig.footer_logo_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.footer_logo_asset_id !== null}
                onUpload={handleUpload}
                onSelect={(assetId) => updateField("footer_logo_asset_id", assetId)}
                onClear={() => updateField("footer_logo_asset_id", null)}
                onDelete={handleDeleteAsset}
                disabled={disabled}
              />

              <div className="form-check">
                <input
                  id="footerLogoDisabled"
                  type="checkbox"
                  className="form-check-input"
                  checked={brandConfig.footer_logo_disabled}
                  onChange={(event) => updateField("footer_logo_disabled", event.target.checked)}
                />
                <label htmlFor="footerLogoDisabled" className="form-check-label">
                  Disable footer logo (falls back to primary when unchecked).
                </label>
              </div>

              <BrandAssetSection
                label="Favicon"
                description="Used in browser tabs. If absent, derived from primary logo."
                kind="favicon"
                asset={findAssetById(brandConfig.favicon_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.favicon_asset_id !== null}
                onUpload={handleUpload}
                onSelect={(assetId) => updateField("favicon_asset_id", assetId)}
                onClear={() => updateField("favicon_asset_id", null)}
                onDelete={handleDeleteAsset}
                disabled={disabled}
              />
            </fieldset>

            <BrandAssetTable assets={assets} onDelete={handleDeleteAsset} disabled={disabled} />
          </>
        )}
      </div>
    </section>
  );
}

type BrandAssetSectionProps = {
  label: string;
  description: string;
  kind: BrandAsset["kind"];
  asset: BrandAsset | null;
  assets: BrandAsset[];
  hasCustomValue: boolean;
  onUpload: (kind: BrandAsset["kind"], file: File) => void | Promise<void>;
  onSelect: (assetId: string | null) => void;
  onClear: () => void;
  onDelete: (assetId: string) => void;
  disabled: boolean;
};

function BrandAssetSection({
  label,
  description,
  kind,
  asset,
  assets,
  hasCustomValue,
  onUpload,
  onSelect,
  onClear,
  onDelete,
  disabled,
}: BrandAssetSectionProps): JSX.Element {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const filteredAssets = assets;

  const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;
    void onUpload(kind, file);
  };

  return (
    <div className="card border-secondary-subtle">
      <div className="card-body d-flex flex-column flex-lg-row gap-3">
        <div className="flex-grow-1">
          <h3 className="h6 mb-1">{label}</h3>
          <p className="text-muted small mb-3">{description}</p>
          <div className="d-flex flex-wrap align-items-start gap-3">
            <div>
              {asset ? (
                <div className="d-flex align-items-center gap-3">
                  {asset.url ? (
                    <img
                      src={asset.url}
                      alt={`${label} preview`}
                      style={{ maxHeight: 64, maxWidth: 120 }}
                      className="border rounded"
                    />
                  ) : (
                    <div
                      className="border rounded bg-light d-flex align-items-center justify-content-center"
                      style={{ height: 64, width: 120 }}
                    >
                      <span className="text-muted small">Preview</span>
                    </div>
                  )}
                  <div className="small text-muted">
                    <div>{asset.name}</div>
                    <div>{asset.mime}</div>
                    <div>{formatBytes(asset.size_bytes)}</div>
                  </div>
                </div>
              ) : (
                <div className="text-muted small">No asset selected.</div>
              )}
            </div>
            <div className="d-flex flex-column align-items-center gap-2">
              <PlacementIllustration kind={kind} />
              <span className="text-muted small">Placement guide</span>
            </div>
          </div>
        </div>
        <div className="d-flex flex-column gap-2">
          <button
            type="button"
            className="btn btn-outline-primary btn-sm"
            onClick={() => inputRef.current?.click()}
            disabled={disabled}
          >
            Upload new
          </button>
          <input
            ref={inputRef}
            type="file"
            accept={ALLOWED_TYPES.join(",")}
            className="d-none"
            aria-label={`Upload ${label}`}
            onChange={handleFileChange}
          />
          <select
            className="form-select form-select-sm"
            value={asset?.id ?? ""}
            onChange={(event) => onSelect(event.target.value || null)}
            disabled={disabled || filteredAssets.length === 0}
          >
            <option value="">Select existing…</option>
            {filteredAssets.map((item) => (
              <option key={item.id} value={item.id}>
                {`${assetLabel[item.kind]} • ${item.name}`}
              </option>
            ))}
          </select>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={onClear}
            disabled={disabled || (!asset && !hasCustomValue)}
          >
            Restore default
          </button>
          <button
            type="button"
            className="btn btn-outline-danger btn-sm"
            onClick={() => asset && onDelete(asset.id)}
            disabled={disabled || !asset}
          >
            Delete asset
          </button>
        </div>
      </div>
    </div>
  );
}

function PlacementIllustration({ kind }: { kind: BrandAsset["kind"] }): JSX.Element {
  const containerStyle: CSSProperties = {
    position: "relative",
    width: 120,
    height: 80,
    borderRadius: 6,
    border: "1px solid var(--bs-border-color-translucent, #ced4da)",
    background: "#f8f9fa",
    overflow: "hidden",
  };

  const headerStyle: CSSProperties = {
    position: "absolute",
    top: 0,
    left: 0,
    right: 0,
    height: 18,
    background: "#dee2e6",
  };

  const bodyStyle: CSSProperties = {
    position: "absolute",
    top: 18,
    bottom: 18,
    left: 0,
    right: 0,
    background: "#ffffff",
  };

  const footerStyle: CSSProperties = {
    position: "absolute",
    bottom: 0,
    left: 0,
    right: 0,
    height: 18,
    background: "#dee2e6",
  };

  const highlightBase: CSSProperties = {
    position: "absolute",
    background: "rgba(13, 110, 253, 0.35)",
    borderRadius: 4,
  };

  const highlight: CSSProperties = (() => {
    switch (kind) {
      case "primary_logo":
        return { top: 6, left: 8, width: 44, height: 12 };
      case "secondary_logo":
        return { top: 34, left: 54, width: 36, height: 14 };
      case "header_logo":
        return { top: 6, left: 60, width: 40, height: 12 };
      case "footer_logo":
        return { bottom: 6, left: 54, width: 36, height: 12 };
      case "favicon":
        return { top: 6, right: 8, width: 12, height: 12 };
      default:
        return { top: 6, left: 8, width: 44, height: 12 };
    }
  })();

  return (
    <div style={containerStyle} aria-hidden="true">
      <div style={headerStyle} />
      <div style={bodyStyle} />
      <div style={footerStyle} />
      <div style={{ ...highlightBase, ...highlight }} />
    </div>
  );
}

type BrandAssetTableProps = {
  assets: BrandAsset[];
  onDelete: (assetId: string) => void;
  disabled: boolean;
};

function BrandAssetTable({ assets, onDelete, disabled }: BrandAssetTableProps): JSX.Element {
  if (assets.length === 0) {
    return (
      <div className="alert alert-secondary mb-0" role="note">
        No uploaded branding assets yet.
      </div>
    );
  }

  return (
    <div className="table-responsive">
      <table className="table table-sm align-middle">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Kind</th>
            <th scope="col">Size</th>
            <th scope="col">Uploaded</th>
            <th scope="col" className="text-end">
              Actions
            </th>
          </tr>
        </thead>
        <tbody>
          {assets.map((asset) => (
            <tr key={asset.id}>
              <td>{asset.name}</td>
              <td>{assetLabel[asset.kind]}</td>
              <td>{formatBytes(asset.size_bytes)}</td>
              <td>{formatTimestamp(asset.created_at)}</td>
              <td className="text-end">
                <button
                  type="button"
                  className="btn btn-outline-danger btn-sm"
                  onClick={() => onDelete(asset.id)}
                  disabled={disabled}
                >
                  Delete
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return "0 B";
  const units = ["B", "KB", "MB", "GB"];
  const idx = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const value = bytes / Math.pow(1024, idx);
  return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[idx]}`;
}

function formatTimestamp(timestamp: string): string {
  const date = new Date(timestamp);
  if (Number.isNaN(date.getTime())) return timestamp;
  return date.toLocaleString();
}
