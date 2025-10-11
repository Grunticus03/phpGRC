import { useCallback, useEffect, useRef, useState } from "react";
import { baseHeaders } from "../../../lib/api";
import { DEFAULT_THEME_SETTINGS, type ThemeSettings } from "../themeData";

type Mutable<T> = T extends object ? { -readonly [K in keyof T]: Mutable<T[K]> } : T;

type BrandingConfig = Mutable<ThemeSettings["brand"]>;

type BrandingResponse = {
  ok?: boolean;
  config?: { ui?: { brand?: BrandingConfig } };
  etag?: string;
};

type BrandAsset = {
  id: string;
  kind: "primary_logo" | "secondary_logo" | "header_logo" | "footer_logo" | "favicon";
  name: string;
  mime: string;
  size_bytes: number;
  sha256: string;
  uploaded_by: string | null;
  created_at: string;
  url?: string;
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

const MAX_FILE_SIZE = 5 * 1024 * 1024;
const ALLOWED_TYPES = ["image/png", "image/jpeg", "image/webp", "image/svg+xml"];

const assetLabel: Record<BrandAsset["kind"], string> = {
  primary_logo: "Primary logo",
  secondary_logo: "Secondary logo",
  header_logo: "Header logo",
  footer_logo: "Footer logo",
  favicon: "Favicon",
};

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
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [uploadMessage, setUploadMessage] = useState<string | null>(null);
  const [readOnly, setReadOnly] = useState(false);

  const etagRef = useRef<string | null>(null);
  const baselineRef = useRef<BrandingConfig | null>(null);

  const loadBranding = useCallback(
    async (options?: { preserveMessage?: boolean }) => {
      setLoading(true);
      if (!options?.preserveMessage) {
        setMessage(null);
      }

      try {
        const [settingsRes, assetsRes] = await Promise.all([
          fetch("/api/settings/ui", {
            method: "GET",
            credentials: "same-origin",
            headers: baseHeaders(),
          }),
          fetch("/api/settings/ui/brand-assets", {
            method: "GET",
            credentials: "same-origin",
            headers: baseHeaders(),
          }),
        ]);

        if (settingsRes.status === 403) {
          setReadOnly(true);
          setMessage("You do not have permission to update branding.");
          etagRef.current = null;
          setBrandConfig(createDefaultBrandConfig());
          baselineRef.current = createDefaultBrandConfig();
          setAssets([]);
          return;
        }

        if (settingsRes.ok) {
          etagRef.current = settingsRes.headers.get("ETag");
          const body = await parseJson<BrandingResponse>(settingsRes);
          const config: BrandingConfig = body?.config?.ui?.brand
            ? { ...(body.config.ui.brand as BrandingConfig) }
            : createDefaultBrandConfig();
          setBrandConfig(config);
          baselineRef.current = { ...config };
        } else {
          setMessage("Failed to load branding settings. Using defaults.");
          setBrandConfig(createDefaultBrandConfig());
          baselineRef.current = createDefaultBrandConfig();
        }

        if (assetsRes.ok) {
          const assetsBody = await parseJson<AssetListResponse>(assetsRes);
          setAssets(assetsBody?.assets ?? []);
        } else {
          setAssets([]);
        }
      } catch {
        setMessage("Failed to load branding settings. Using defaults.");
        etagRef.current = null;
        setBrandConfig(createDefaultBrandConfig());
        baselineRef.current = createDefaultBrandConfig();
        setAssets([]);
      } finally {
        setLoading(false);
      }
    },
    []
  );

  useEffect(() => {
    void loadBranding();
  }, [loadBranding]);

  const updateField = (key: keyof BrandingConfig, value: unknown) => {
    setBrandConfig((prev) => ({ ...prev, [key]: value }));
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
          brand: brandConfig,
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
        await loadBranding({ preserveMessage: true });
        return;
      }

      if (res.status === 404 || res.status === 501) {
        setMessage("Branding save not yet available (stub). Values kept locally.");
        baselineRef.current = { ...brandConfig };
        etagRef.current = null;
        return;
      }

      if (!res.ok) {
        throw new Error(`Save failed (HTTP ${res.status}).`);
      }

      const nextEtag = res.headers.get("ETag") ?? body?.etag ?? null;
      if (nextEtag) etagRef.current = nextEtag;

      if (body?.config?.ui?.brand) {
        const updated: BrandingConfig = { ...(body.config.ui.brand as BrandingConfig) };
        setBrandConfig(updated);
        baselineRef.current = { ...updated };
      } else {
        baselineRef.current = { ...brandConfig };
      }

      setMessage("Branding saved.");
    } catch (err) {
      setMessage(err instanceof Error ? err.message : "Save failed.");
    } finally {
      setSaving(false);
    }
  };

  const handleUpload = async (kind: BrandAsset["kind"], file: File): Promise<void> => {
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

      const mutableAsset = body.asset as BrandAsset;
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
    } catch {
      setUploadMessage("Upload failed.");
    }
  };

  const handleDeleteAsset = async (assetId: string): Promise<void> => {
    if (!window.confirm("Delete this asset? This cannot be undone.")) return;
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
        setMessage("Asset deleted and references cleared. Save to apply.");
      }
    } catch (err) {
      setUploadMessage(err instanceof Error ? err.message : "Delete failed.");
    }
  };

  const disabled = loading || saving || readOnly;

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
            disabled={loading || saving}
          >
            Reload
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => {
              if (baselineRef.current) {
                setBrandConfig({ ...baselineRef.current });
                setMessage("Reverted to last saved branding.");
              }
            }}
            disabled={disabled}
          >
            Reset
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
  onUpload,
  onSelect,
  onClear,
  onDelete,
  disabled,
}: BrandAssetSectionProps): JSX.Element {
  const inputRef = useRef<HTMLInputElement | null>(null);

  const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;
    void onUpload(kind, file);
  };

  const sameKindAssets = assets.filter((item) => item.kind === kind);

  return (
    <div className="card border-secondary-subtle">
      <div className="card-body d-flex flex-column flex-lg-row gap-3">
        <div className="flex-grow-1">
          <h3 className="h6 mb-1">{label}</h3>
          <p className="text-muted small mb-3">{description}</p>
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
            onChange={handleFileChange}
          />
          <select
            className="form-select form-select-sm"
            value={asset?.id ?? ""}
            onChange={(event) => onSelect(event.target.value || null)}
            disabled={disabled || sameKindAssets.length === 0}
          >
            <option value="">Select existing…</option>
            {sameKindAssets.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
              </option>
            ))}
          </select>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={onClear}
            disabled={disabled || !asset}
          >
            Clear selection
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
