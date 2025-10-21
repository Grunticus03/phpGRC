import { useCallback, useEffect, useRef, useState, type CSSProperties } from "react";
import { baseHeaders } from "../../../lib/api";
import { getThemeAccess, deriveThemeAccess, type ThemeAccess } from "../../../lib/themeAccess";
import { updateThemeSettings } from "../../../theme/themeManager";
import { DEFAULT_THEME_SETTINGS, type ThemeSettings } from "../themeData";
import { useToast } from "../../../components/toast/ToastProvider";
import ConfirmModal from "../../../components/modal/ConfirmModal";

type Mutable<T> = T extends object ? { -readonly [K in keyof T]: Mutable<T[K]> } : T;

type BrandingConfig = {
  -readonly [K in keyof ThemeSettings["brand"]]: ThemeSettings["brand"][K] extends null
    ? string | null
    : ThemeSettings["brand"][K] extends string
    ? string
    : ThemeSettings["brand"][K] extends boolean
    ? boolean
    : ThemeSettings["brand"][K] extends object
    ? Mutable<ThemeSettings["brand"][K]>
    : ThemeSettings["brand"][K];
};

type BrandingResponse = {
  ok?: boolean;
  config?: { ui?: ThemeSettings };
  etag?: string;
};

type BrandAsset = {
  id: string;
  profile_id: string;
  kind: "primary_logo" | "secondary_logo" | "header_logo" | "footer_logo" | "favicon" | "background_image";
  name: string;
  display_name?: string;
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
  variants?: Partial<Record<BrandAsset["kind"], BrandAsset>>;
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
const ALLOWED_TYPES = ["image/png", "image/jpeg", "image/webp"];

const assetLabel: Record<BrandAsset["kind"], string> = {
  primary_logo: "Primary logo",
  secondary_logo: "Secondary logo",
  header_logo: "Header logo",
  footer_logo: "Footer logo",
  favicon: "Favicon",
  background_image: "Background image",
};

const assetDownloadUrl = (assetId: string): string =>
  `/settings/ui/brand-assets/${encodeURIComponent(assetId)}/download`;

const mergeAssetsById = (current: BrandAsset[], incoming: BrandAsset[]): BrandAsset[] => {
  if (incoming.length === 0) return current;
  const map = new Map<string, BrandAsset>();
  current.forEach((asset) => {
    map.set(asset.id, asset);
  });
  incoming.forEach((asset) => {
    map.set(asset.id, {
      ...asset,
      url: asset.url ?? assetDownloadUrl(asset.id),
    });
  });
  return Array.from(map.values()).sort((a, b) => {
    const aTime = Date.parse(a.created_at ?? "");
    const bTime = Date.parse(b.created_at ?? "");
    if (Number.isNaN(aTime) || Number.isNaN(bTime)) {
      return b.id.localeCompare(a.id);
    }
    return bTime - aTime;
  });
};

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
    assets: {
      ...DEFAULT_THEME_SETTINGS.brand.assets,
      ...((incoming?.brand?.assets ?? DEFAULT_THEME_SETTINGS.brand.assets) as ThemeSettings["brand"]["assets"]),
    },
  },
});

const normalizeBrandConfig = (source?: Partial<BrandingConfig>): BrandingConfig => {
  const { assets, ...rest } = source ?? {};
  return {
    title_text:
      typeof rest?.title_text === "string" && rest.title_text.trim() !== ""
        ? rest.title_text
        : DEFAULT_THEME_SETTINGS.brand.title_text,
    footer_logo_disabled:
      typeof rest?.footer_logo_disabled === "boolean"
        ? rest.footer_logo_disabled
        : DEFAULT_THEME_SETTINGS.brand.footer_logo_disabled,
    favicon_asset_id: rest?.favicon_asset_id ?? null,
    primary_logo_asset_id: rest?.primary_logo_asset_id ?? null,
    secondary_logo_asset_id: rest?.secondary_logo_asset_id ?? null,
    header_logo_asset_id: rest?.header_logo_asset_id ?? null,
    footer_logo_asset_id: rest?.footer_logo_asset_id ?? null,
    background_login_asset_id: rest?.background_login_asset_id ?? null,
    background_main_asset_id: rest?.background_main_asset_id ?? null,
    assets: {
      filesystem_path:
        typeof assets?.filesystem_path === "string" && assets.filesystem_path.trim() !== ""
          ? assets.filesystem_path
          : DEFAULT_THEME_SETTINGS.brand.assets.filesystem_path,
    },
  } as BrandingConfig;
};

const createDefaultBrandConfig = (): BrandingConfig =>
  normalizeBrandConfig(DEFAULT_THEME_SETTINGS.brand as unknown as Partial<BrandingConfig>);

const cloneBrandConfig = (config: BrandingConfig): BrandingConfig => normalizeBrandConfig({ ...config });

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
  const toast = useToast();
  const { success: showSuccess, info: showInfo, warning: showWarning, danger: showDanger } = toast;
  const [readOnly, setReadOnly] = useState(false);
  const [, setThemeAccess] = useState<ThemeAccess | null>(null);
  const [isCreatingProfile, setIsCreatingProfile] = useState(false);
  const [newProfileName, setNewProfileName] = useState("");
  const [deleteAssetTarget, setDeleteAssetTarget] = useState<BrandAsset | null>(null);
  const [deleteAssetBusy, setDeleteAssetBusy] = useState(false);
  const [deleteProfileTarget, setDeleteProfileTarget] = useState<BrandProfile | null>(null);
  const [deleteProfileBusy, setDeleteProfileBusy] = useState(false);
  const [deleteProfileError, setDeleteProfileError] = useState<string | null>(null);
  const assetUploadInputRef = useRef<HTMLInputElement | null>(null);

  const etagRef = useRef<string | null>(null);
  const baselineRef = useRef<BrandingConfig | null>(null);
  const settingsRef = useRef<ThemeSettings>(DEFAULT_THEME_SETTINGS);
  const selectedProfileRef = useRef<string | null>(null);
  const accessRef = useRef<ThemeAccess | null>(null);

  useEffect(() => {
    selectedProfileRef.current = selectedProfileId;
  }, [selectedProfileId]);

  useEffect(() => {
    let active = true;
    void getThemeAccess()
      .then((access) => {
        if (!active) return;
        accessRef.current = access;
        setThemeAccess(access);
        setReadOnly(!access.canManage);
      })
      .catch(() => {
        if (!active) return;
        const fallback = deriveThemeAccess([]);
        accessRef.current = fallback;
        setThemeAccess(fallback);
        setReadOnly(true);
      });

    return () => {
      active = false;
    };
  }, []);

  const fetchAssets = useCallback(async (profileId: string): Promise<BrandAsset[]> => {
    if (!profileId) return [];
    try {
      const res = await fetch(`/settings/ui/brand-assets?profile_id=${encodeURIComponent(profileId)}`, {
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
      const res = await fetch("/settings/ui/brand-profiles", {
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
      const shouldNotify = !options?.preserveMessage;

      try {
        const settingsRes = await fetch("/settings/ui", {
          method: "GET",
          credentials: "same-origin",
          headers: baseHeaders(),
        });

        if (settingsRes.status === 403) {
          setReadOnly(true);
          if (shouldNotify) {
            showWarning("You do not have permission to update branding.");
          }
          etagRef.current = null;
          setProfiles([]);
          setSelectedProfileId(null);
          const defaults = createDefaultBrandConfig();
          setBrandConfig(defaults);
          baselineRef.current = cloneBrandConfig(defaults);
          previewBrand(defaults);
          setAssets([]);
          return;
        }

        let uiSettings: ThemeSettings | null = null;
        if (settingsRes.ok) {
          etagRef.current = settingsRes.headers.get("ETag");
          const body = await parseJson<BrandingResponse>(settingsRes);
          uiSettings = body?.config?.ui ?? null;
        } else {
          if (shouldNotify) {
            showWarning("Failed to load branding settings. Using defaults.");
          }
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
          const config = cloneBrandConfig(selectedProfile.brand as BrandingConfig);
          setBrandConfig(config);
          baselineRef.current = cloneBrandConfig(config);
          previewBrand(config);
          const assetList = await fetchAssets(selectedProfile.id);
          setAssets(assetList);
        } else {
          const defaults = createDefaultBrandConfig();
          setBrandConfig(defaults);
          baselineRef.current = cloneBrandConfig(defaults);
          previewBrand(defaults);
          setAssets([]);
        }

        setReadOnly(accessRef.current ? !accessRef.current.canManage : false);
      } catch {
        if (shouldNotify) {
          showWarning("Failed to load branding settings. Using defaults.");
        }
        etagRef.current = null;
        const defaults = createDefaultBrandConfig();
        const normalized = normalizeSettings(null);
        settingsRef.current = normalized;
        updateThemeSettings(normalized);
        setBrandConfig(defaults);
        baselineRef.current = cloneBrandConfig(defaults);
        previewBrand(defaults);
        setProfiles([]);
        setSelectedProfileId(null);
        setAssets([]);
        setReadOnly(accessRef.current ? !accessRef.current.canManage : false);
      } finally {
        setLoading(false);
      }
    },
    [fetchAssets, fetchProfiles, showWarning]
  );

  const initializedRef = useRef(false);

  useEffect(() => {
    if (initializedRef.current) return;
    initializedRef.current = true;
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
      baselineRef.current = cloneBrandConfig(defaults);
      setAssets([]);
      return;
    }

    setSelectedProfileId(profileId);
    const profile = profiles.find((item) => item.id === profileId) ?? null;
    if (profile) {
      const config = cloneBrandConfig(profile.brand as BrandingConfig);
      setBrandConfig(config);
      baselineRef.current = cloneBrandConfig(config);
      previewBrand(config);
      if (profile.is_locked) {
        showWarning("Default profile is locked. Create a new profile to customize branding.");
      }
      setAssets([]);
      void fetchAssets(profile.id).then((list) => setAssets(list));
    } else {
      const defaults = createDefaultBrandConfig();
      setBrandConfig(defaults);
      baselineRef.current = cloneBrandConfig(defaults);
      previewBrand(defaults);
      setAssets([]);
    }
  };

  const handleCreateProfile = async () => {
    const name = newProfileName.trim();
    if (name === "") {
      showWarning("Profile name is required.");
      return;
    }

    setProfileSaving(true);
    try {
      const res = await fetch("/settings/ui/brand-profiles", {
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
      showSuccess(`Profile "${body.profile.name}" created. Configure and save to apply.`);
      await loadBranding({ preserveMessage: true, profileId: body.profile.id });
    } catch (error) {
      showDanger(error instanceof Error ? error.message : "Failed to create profile.");
    } finally {
      setProfileSaving(false);
    }
  };

  const handleActivateProfile = async (profileId: string) => {
    setProfileSaving(true);
    try {
      const res = await fetch(`/settings/ui/brand-profiles/${encodeURIComponent(profileId)}/activate`, {
        method: "POST",
        credentials: "same-origin",
        headers: baseHeaders({ "Content-Type": "application/json" }),
      });

      if (!res.ok) {
        const body = await parseJson<Record<string, unknown>>(res);
        const errMsg = typeof body?.message === "string" ? body.message : "Failed to activate profile.";
        throw new Error(errMsg);
      }

      showSuccess("Profile activated.");
      await loadBranding({ preserveMessage: true, profileId });
    } catch (error) {
      showDanger(error instanceof Error ? error.message : "Failed to activate profile.");
    } finally {
      setProfileSaving(false);
    }
  };

  const handleDeleteProfile = async () => {
    if (!deleteProfileTarget) {
      return;
    }

    setDeleteProfileBusy(true);
    try {
      const res = await fetch(`/settings/ui/brand-profiles/${encodeURIComponent(deleteProfileTarget.id)}`, {
        method: "DELETE",
        credentials: "same-origin",
        headers: baseHeaders(),
      });

      const body = await parseJson<Record<string, unknown>>(res);
      if (!res.ok) {
        const errMsg = typeof body?.message === "string" ? (body.message as string) : "Failed to delete profile.";
        throw new Error(errMsg);
      }

      showSuccess("Profile deleted.");
      setDeleteProfileError(null);
      setDeleteProfileTarget(null);
      await loadBranding({ preserveMessage: true });
    } catch (error) {
      setDeleteProfileError(error instanceof Error ? error.message : "Failed to delete profile.");
    } finally {
      setDeleteProfileBusy(false);
    }
  };

  const previewBrand = (config: BrandingConfig) => {
    const base = settingsRef.current;
    const draft: ThemeSettings = {
      ...base,
      brand: { ...config, assets: { ...config.assets } } as ThemeSettings["brand"],
    };
    updateThemeSettings(draft);
  };

  const updateField = (key: keyof BrandingConfig, value: unknown) => {
    setBrandConfig((prev) => {
      const next = { ...prev, [key]: value } as BrandingConfig;
      if (key === "assets" && value && typeof value === "object") {
        next.assets = { ...(value as BrandingConfig["assets"]) };
      } else {
        next.assets = { ...prev.assets };
      }
      previewBrand(next);
      return next;
    });
  };

  const updateAssetsPath = (value: string) => {
    setBrandConfig((prev) => {
      const next = cloneBrandConfig(prev);
      next.assets.filesystem_path = value;
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
      brandConfig.background_login_asset_id !== baseline.background_login_asset_id ||
      brandConfig.background_main_asset_id !== baseline.background_main_asset_id ||
      brandConfig.footer_logo_disabled !== baseline.footer_logo_disabled ||
      brandConfig.assets.filesystem_path !== baseline.assets.filesystem_path
    );
  };

  const handleSave = async (): Promise<void> => {
    if (readOnly) {
      showWarning("Branding is read-only for your account.");
      return;
    }
    if (!selectedProfile) {
      showWarning("Select a branding profile before saving.");
      return;
    }
    if (selectedProfile.is_locked) {
      showWarning("Default branding profile cannot be modified.");
      return;
    }
    if (!hasChanges()) {
      showInfo("No branding changes to save.");
      return;
    }

    const etag = etagRef.current;
    if (!etag) {
      await loadBranding();
      showInfo("Settings version refreshed. Please retry.");
      return;
    }

    setSaving(true);

    try {
      const payload = {
        ui: {
          brand: {
            ...brandConfig,
            profile_id: selectedProfile.id,
          },
        },
      };

      const performSave = async (ifMatch: string) => {
        const response = await fetch("/settings/ui", {
          method: "PUT",
          credentials: "same-origin",
          headers: baseHeaders({
            "Content-Type": "application/json",
            "If-Match": ifMatch,
          }),
          body: JSON.stringify(payload),
        });
        const responseBody = await parseJson<BrandingResponse>(response);
        return { response, responseBody };
      };

      let { response, responseBody } = await performSave(etag);

      if (response.status === 409) {
        const nextEtag = responseBody?.etag ?? response.headers.get("ETag");
        if (nextEtag && nextEtag !== etag) {
          etagRef.current = nextEtag;
          showInfo("Branding changed elsewhere. Retrying with the latest version.");
          ({ response, responseBody } = await performSave(nextEtag));
        } else {
          const fallbackEtag = nextEtag ?? null;
          if (fallbackEtag) {
            etagRef.current = fallbackEtag;
          }
          showWarning("Branding changed elsewhere. Reloaded latest values.");
          await loadBranding({ preserveMessage: true, profileId: selectedProfile.id });
          return;
        }
      }

      if (!response.ok) {
        throw new Error(`Save failed (HTTP ${response.status}).`);
      }

      const nextEtag = response.headers.get("ETag") ?? responseBody?.etag ?? null;
      if (nextEtag) etagRef.current = nextEtag;

      if (responseBody?.config?.ui) {
        const normalized = normalizeSettings(responseBody.config.ui);
        settingsRef.current = normalized;
        updateThemeSettings(normalized);
        const updated = cloneBrandConfig(normalized.brand as BrandingConfig);
        setBrandConfig(updated);
        baselineRef.current = cloneBrandConfig(updated);
      } else {
        baselineRef.current = cloneBrandConfig(brandConfig);
      }

      showSuccess(`Branding saved for "${selectedProfile.name}".`);
      await loadBranding({ preserveMessage: true, profileId: selectedProfile.id });
    } catch (err) {
      showDanger(err instanceof Error ? err.message : "Save failed.");
    } finally {
      setSaving(false);
    }
  };

  const handleUpload = async (kind: BrandAsset["kind"], file: File): Promise<void> => {
    if (!selectedProfile) {
      showWarning("Select a branding profile before uploading.");
      return;
    }
    if (selectedProfile.is_locked) {
      showWarning("Default branding profile does not accept uploads.");
      return;
    }
    if (!ALLOWED_TYPES.includes(file.type)) {
      showWarning(`Unsupported file type: ${file.type || "unknown"}.`);
      return;
    }
    if (file.size > MAX_FILE_SIZE) {
      showWarning("File exceeds 5 MB limit.");
      return;
    }

    const formData = new FormData();
    formData.append("kind", kind);
    formData.append("profile_id", selectedProfile.id);
    formData.append("file", file);

    try {
      const res = await fetch("/settings/ui/brand-assets", {
        method: "POST",
        credentials: "same-origin",
        headers: baseHeaders(),
        body: formData,
      });

      const body = await parseJson<UploadResponse>(res);
      const variants = body?.variants ?? null;
      if (!res.ok || !body?.asset || !variants || typeof variants !== "object") {
        const errMsg = typeof body?.message === "string" ? body.message : "Upload failed.";
        showDanger(errMsg);
        return;
      }

      const variantAssets = Object.values(variants).filter((variant): variant is BrandAsset => Boolean(variant));
      const uploadedAssets = mergeAssetsById([], [body.asset, ...variantAssets]);

      setAssets((prev) => mergeAssetsById(prev, uploadedAssets));

      showSuccess("Upload successful. Select it from the dropdown to apply.");

      void fetchAssets(selectedProfile.id).then((list) => {
        setAssets((prev) => mergeAssetsById(prev, list));
      });
    } catch {
      showDanger("Upload failed.");
    }
  };

  const requestDeleteAsset = (asset: BrandAsset): void => {
    if (!selectedProfile || selectedProfile.is_locked) {
      showWarning("Cannot modify assets for this profile.");
      return;
    }
    setDeleteAssetTarget(asset);
  };

  const dismissDeleteAssetModal = (): void => {
    if (deleteAssetBusy) return;
    setDeleteAssetTarget(null);
  };

  const handleDeleteAsset = async (): Promise<void> => {
    if (!deleteAssetTarget) return;
    const profile = selectedProfile;
    if (!profile || profile.is_locked) {
      showWarning("Cannot modify assets for this profile.");
      setDeleteAssetTarget(null);
      return;
    }
    setDeleteAssetBusy(true);
    try {
      const res = await fetch(`/settings/ui/brand-assets/${encodeURIComponent(deleteAssetTarget.id)}`, {
        method: "DELETE",
        credentials: "same-origin",
        headers: baseHeaders(),
      });
      if (!res.ok) {
        throw new Error(`Delete failed (${res.status})`);
      }
      showSuccess("Asset deleted.");
      await loadBranding({ preserveMessage: true, profileId: profile.id });
      setDeleteAssetTarget(null);
    } catch (err) {
      showDanger(err instanceof Error ? err.message : "Delete failed.");
    } finally {
      setDeleteAssetBusy(false);
    }
  };

  const disabled = loading || saving || readOnly || profileLocked || profileSaving;

  const findAssetById = (id: string | null): BrandAsset | null => {
    if (!id) return null;
    return assets.find((asset) => asset.id === id) ?? null;
  };

  const deleteAssetLabel =
    deleteAssetTarget?.display_name?.trim() ||
    deleteAssetTarget?.name?.trim() ||
    deleteAssetTarget?.id ||
    "";

  return (
    <>
      <section className="card mb-4" aria-label="branding-card">
      <div className="card-header d-flex justify-content-between align-items-center">
        <h2 className="h5 mb-0">Branding</h2>
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
                showInfo("Reverted to last saved branding.");
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
                        setIsCreatingProfile(true);
                        setNewProfileName("");
                      }}
                      disabled={profileSaving || readOnly}
                    >
                      New profile
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-danger btn-sm"
                      onClick={() => {
                        if (!selectedProfile || selectedProfile.is_default || selectedProfile.is_locked) {
                          return;
                        }
                        setDeleteProfileError(null);
                        setDeleteProfileTarget(selectedProfile);
                      }}
                      disabled={
                        !selectedProfile ||
                        selectedProfile.is_default ||
                        selectedProfile.is_locked ||
                        profileSaving ||
                        readOnly
                      }
                    >
                      Delete profile
                    </button>
                  </div>
                </div>
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

              <div>
                <label htmlFor="brandAssetPath" className="form-label fw-semibold">
                  Brand assets directory
                </label>
                <input
                  id="brandAssetPath"
                  type="text"
                  className="form-control"
                  value={brandConfig.assets.filesystem_path}
                  onChange={(event) => updateAssetsPath(event.target.value)}
                  placeholder="/opt/phpgrc/shared/brands"
                  disabled={disabled}
                />
                <div className="form-text">
                  Absolute path on the server; a sub-folder per profile is created automatically.
                </div>
              </div>

            <div className="d-flex flex-column align-items-start gap-2 mb-3">
              <button
                type="button"
                className="btn btn-outline-primary btn-sm"
                onClick={() => {
                  if (!disabled) {
                    assetUploadInputRef.current?.click();
                  }
                }}
                disabled={disabled}
              >
                Upload asset
              </button>
              <input
                ref={assetUploadInputRef}
                type="file"
                accept={ALLOWED_TYPES.join(",")}
                className="d-none"
                aria-label="Upload asset"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) {
                    void handleUpload("primary_logo", file);
                  }
                  event.target.value = "";
                }}
                disabled={disabled}
              />
              <div className="form-text mb-0">PNG, JPG, or WebP up to 5 MB.</div>
            </div>

              <BrandAssetSection
                label="Primary logo"
                description="Displayed in header and as fallback for other locations."
                kind="primary_logo"
                asset={findAssetById(brandConfig.primary_logo_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.primary_logo_asset_id !== null}
                onSelect={(assetId) => updateField("primary_logo_asset_id", assetId)}
                onClear={() => updateField("primary_logo_asset_id", null)}
                disabled={disabled}
              />

              <BrandAssetSection
                label="Secondary logo"
                description="Used when the primary logo is not suitable; falls back to primary otherwise."
                kind="secondary_logo"
                asset={findAssetById(brandConfig.secondary_logo_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.secondary_logo_asset_id !== null}
                onSelect={(assetId) => updateField("secondary_logo_asset_id", assetId)}
                onClear={() => updateField("secondary_logo_asset_id", null)}
                disabled={disabled}
              />

              <BrandAssetSection
                label="Header logo"
                description="Optional specialized logo for application header."
                kind="header_logo"
                asset={findAssetById(brandConfig.header_logo_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.header_logo_asset_id !== null}
                onSelect={(assetId) => updateField("header_logo_asset_id", assetId)}
                onClear={() => updateField("header_logo_asset_id", null)}
                disabled={disabled}
              />

              <BrandAssetSection
                label="Footer logo"
                description="Displayed in footer; falls back to primary when absent."
                kind="footer_logo"
                asset={findAssetById(brandConfig.footer_logo_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.footer_logo_asset_id !== null}
                onSelect={(assetId) => updateField("footer_logo_asset_id", assetId)}
                onClear={() => updateField("footer_logo_asset_id", null)}
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
                onSelect={(assetId) => updateField("favicon_asset_id", assetId)}
                onClear={() => updateField("favicon_asset_id", null)}
                disabled={disabled}
              />

              <BrandAssetSection
                label="Login background"
                description="Displayed on the login screen. Falls back to the default theme background when unset."
                kind="background_image"
                asset={findAssetById(brandConfig.background_login_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.background_login_asset_id !== null}
                onSelect={(assetId) => updateField("background_login_asset_id", assetId)}
                onClear={() => updateField("background_login_asset_id", null)}
                disabled={disabled}
              />

              <BrandAssetSection
                label="Application background"
                description="Optional background for the main application pane. Falls back to the theme background when unset."
                kind="background_image"
                asset={findAssetById(brandConfig.background_main_asset_id)}
                assets={assets}
                hasCustomValue={brandConfig.background_main_asset_id !== null}
                onSelect={(assetId) => updateField("background_main_asset_id", assetId)}
                onClear={() => updateField("background_main_asset_id", null)}
                disabled={disabled}
              />
            </fieldset>

            <BrandAssetTable
              assets={assets.filter((asset) => asset.kind === "primary_logo" || asset.kind === "background_image")}
              onDelete={requestDeleteAsset}
              disabled={disabled}
            />
          </>
        )}
      </div>
    </section>
      {isCreatingProfile && (
        <ConfirmModal
          open
          title="Create branding profile"
          confirmLabel={profileSaving ? "Creating…" : "Create"}
          busy={profileSaving}
          confirmDisabled={newProfileName.trim().length === 0}
          initialFocus="none"
          onCancel={() => {
            if (profileSaving) return;
            setIsCreatingProfile(false);
            setNewProfileName("");
          }}
          onConfirm={() => {
            if (!profileSaving) {
              void handleCreateProfile();
            }
          }}
          disableBackdropClose={profileSaving}
        >
          <div className="mb-3">
            <label htmlFor="newProfileName" className="form-label">
              Profile name
            </label>
            <input
              id="newProfileName"
              type="text"
              className="form-control"
              placeholder="Profile name"
              value={newProfileName}
              maxLength={120}
              onChange={(event) => setNewProfileName(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === "Enter" && !profileSaving && newProfileName.trim().length > 0) {
                  event.preventDefault();
                  void handleCreateProfile();
                }
              }}
              disabled={profileSaving}
              autoFocus
            />
          </div>
        </ConfirmModal>
      )}
      {deleteProfileTarget && (
        <ConfirmModal
          open
          title={`Delete "${deleteProfileTarget.name}" profile?`}
          busy={deleteProfileBusy}
          confirmLabel={deleteProfileBusy ? "Deleting…" : "Delete"}
          confirmTone="danger"
          onCancel={() => {
            if (deleteProfileBusy) return;
            setDeleteProfileTarget(null);
            setDeleteProfileError(null);
          }}
          onConfirm={() => {
            if (!deleteProfileBusy) {
              void handleDeleteProfile();
            }
          }}
          disableBackdropClose={deleteProfileBusy}
        >
          <p className="mb-2">
            This removes the branding profile and any uploaded assets associated with it. This action cannot be undone.
          </p>
          {deleteProfileError && <div className="text-danger small mb-0">{deleteProfileError}</div>}
        </ConfirmModal>
      )}
      {deleteAssetTarget && (
        <ConfirmModal
          open
          title={`Delete ${deleteAssetLabel || "asset"}?`}
          busy={deleteAssetBusy}
          confirmLabel={deleteAssetBusy ? "Deleting…" : "Delete"}
          confirmTone="danger"
          onCancel={dismissDeleteAssetModal}
          onConfirm={() => {
            if (!deleteAssetBusy) {
              void handleDeleteAsset();
            }
          }}
          disableBackdropClose={deleteAssetBusy}
        >
          <p className="mb-0">This action cannot be undone.</p>
        </ConfirmModal>
      )}
    </>
  );
}

type BrandAssetSectionProps = {
  label: string;
  description: string;
  kind: BrandAsset["kind"];
  asset: BrandAsset | null;
  assets: BrandAsset[];
  hasCustomValue: boolean;
  onSelect: (assetId: string | null) => void;
  onClear: () => void;
  disabled: boolean;
};

function BrandAssetSection({
  label,
  description,
  kind,
  asset,
  assets,
  hasCustomValue,
  onSelect,
  onClear,
  disabled,
}: BrandAssetSectionProps): JSX.Element {
  const filteredAssets = assets.filter((candidate) => candidate.kind === kind);

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
                    <div>{asset.display_name ?? asset.name}</div>
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
          {kind !== "primary_logo" && kind !== "background_image" && (
            <div className="text-muted small" data-testid={`auto-managed-${kind}`}>
              Managed via asset upload.
            </div>
          )}
          <select
            className="form-select form-select-sm"
            value={asset?.id ?? ""}
            onChange={(event) => onSelect(event.target.value || null)}
            disabled={disabled || filteredAssets.length === 0}
            aria-label={`${label} asset selection`}
          >
            <option value="">Select existing…</option>
            {filteredAssets.map((item) => (
              <option key={item.id} value={item.id}>
                {item.display_name ?? item.name}
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
      case "background_image":
        return { top: 18, left: 4, right: 4, bottom: 22 };
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
  onDelete: (asset: BrandAsset) => void;
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
              <td>{asset.display_name ?? asset.name}</td>
              <td>{assetLabel[asset.kind]}</td>
              <td>{formatBytes(asset.size_bytes)}</td>
              <td>{formatTimestamp(asset.created_at)}</td>
              <td className="text-end">
                <button
                  type="button"
                  className="btn btn-outline-danger btn-sm"
                  onClick={() => onDelete(asset)}
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
