import { FormEvent, useEffect, useRef, useState } from "react";
import { apiGet, apiPost, apiPut } from "../../lib/api";
import { DEFAULT_TIME_FORMAT, normalizeTimeFormat, type TimeFormat } from "../../lib/formatters";

type EffectiveConfig = {
  core: {
    metrics?: {
      cache_ttl_seconds?: number;
      rbac_denies?: { window_days?: number };
    };
    rbac?: { require_auth?: boolean; user_search?: { default_per_page?: number } };
    audit?: { retention_days?: number };
    evidence?: { blob_storage_path?: string; max_mb?: number };
    ui?: { time_format?: string };
  };
};

type SettingsSnapshot = {
  cacheTtl: number;
  rbacDays: number;
  rbacRequireAuth: boolean;
  rbacUserSearchPerPage: number;
  retentionDays: number;
  timeFormat: TimeFormat;
  evidenceBlobPath: string;
  evidenceMaxMb: number;
};

type SettingsPayload = {
  apply: true;
  rbac?: {
    require_auth?: boolean;
    user_search?: { default_per_page: number };
  };
  audit?: { retention_days: number };
  metrics?: {
    cache_ttl_seconds?: number;
    rbac_denies?: { window_days: number };
  };
  ui?: { time_format: TimeFormat };
  evidence?: { blob_storage_path?: string; max_mb?: number };
};

type EvidencePurgeResponse = { ok?: boolean; deleted?: number; note?: string };

const TIME_FORMAT_OPTIONS: Array<{ value: TimeFormat; label: string; example: string }> = [
  { value: "LOCAL", label: "Local date & time", example: "Example: 9/30/2025, 5:23:01 PM" },
  { value: "ISO_8601", label: "ISO 8601 (UTC)", example: "Example: 2025-09-30 21:23:01Z" },
  { value: "RELATIVE", label: "Relative time", example: "Example: 5 minutes ago" },
];


export default function Settings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const [cacheTtl, setCacheTtl] = useState<number>(0);
  const [rbacDays, setRbacDays] = useState<number>(7);
  const [rbacRequireAuth, setRbacRequireAuth] = useState<boolean>(false);
  const [rbacUserSearchPerPage, setRbacUserSearchPerPage] = useState<number>(50);
  const [retentionDays, setRetentionDays] = useState<number>(365);
  const [timeFormat, setTimeFormat] = useState<TimeFormat>(DEFAULT_TIME_FORMAT);
  const [evidenceBlobPath, setEvidenceBlobPath] = useState<string>("");
  const [evidenceMaxMb, setEvidenceMaxMb] = useState<number>(25);
  const blobPlaceholderDefault = "/opt/phpgrc/shared/blobs";
  const [blobPathFocused, setBlobPathFocused] = useState(false);
  const [purging, setPurging] = useState(false);

  const snapshotRef = useRef<SettingsSnapshot | null>(null);

  useEffect(() => {
    void (async () => {
      setLoading(true);
      setMsg(null);
      try {
        const json = await apiGet<{ ok: boolean; config?: EffectiveConfig }>("/api/admin/settings");
        const core = json?.config?.core ?? {};
        const metrics = core.metrics ?? {};
        const rbac = core.rbac ?? {};
        const audit = core.audit ?? {};
        const evidence = core.evidence ?? {};

        const nextCacheTtl = clamp(Number(metrics.cache_ttl_seconds ?? 0), 0, 2_592_000);
        const nextRbacDays = clamp(Number(metrics.rbac_denies?.window_days ?? 7), 7, 365);
        const nextRequireAuth = Boolean(rbac.require_auth ?? false);
        const nextPerPage = clamp(Number(rbac.user_search?.default_per_page ?? 50), 1, 500);
        const nextRetention = clamp(Number(audit.retention_days ?? 365), 1, 730);
        const nextTimeFormat = normalizeTimeFormat(core.ui?.time_format);
        const nextBlobPath = typeof evidence?.blob_storage_path === "string" ? evidence.blob_storage_path.trim() : "";
        const nextMaxMb = clamp(Number(evidence?.max_mb ?? 25), 1, 4096);

        setCacheTtl(nextCacheTtl);
        setRbacDays(nextRbacDays);
        setRbacRequireAuth(nextRequireAuth);
        setRbacUserSearchPerPage(nextPerPage);
        setRetentionDays(nextRetention);
        setTimeFormat(nextTimeFormat);
        setEvidenceBlobPath(nextBlobPath);
        setEvidenceMaxMb(nextMaxMb);

        snapshotRef.current = {
          cacheTtl: nextCacheTtl,
          rbacDays: nextRbacDays,
          rbacRequireAuth: nextRequireAuth,
          rbacUserSearchPerPage: nextPerPage,
          retentionDays: nextRetention,
          timeFormat: nextTimeFormat,
          evidenceBlobPath: nextBlobPath,
          evidenceMaxMb: nextMaxMb,
        };
      } catch {
        setMsg("Failed to load settings.");
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const clamp = (n: number, min: number, max: number) => Math.max(min, Math.min(max, Math.trunc(n)));

  const snapshotFromState = (): SettingsSnapshot => ({
    cacheTtl: clamp(Number(cacheTtl) || 0, 0, 2_592_000),
    rbacDays: clamp(Number(rbacDays) || 7, 7, 365),
    rbacRequireAuth,
    rbacUserSearchPerPage: clamp(Number(rbacUserSearchPerPage) || 50, 1, 500),
    retentionDays: clamp(Number(retentionDays) || 365, 1, 730),
    timeFormat,
    evidenceBlobPath: evidenceBlobPath.trim(),
    evidenceMaxMb: clamp(Number(evidenceMaxMb) || 25, 1, 4096),
  });

  const buildPayload = (): SettingsPayload => {
    const current = snapshotFromState();
    const baseline = snapshotRef.current;

    const payload: SettingsPayload = { apply: true };

    if (!baseline) {
      payload.rbac = {
        require_auth: current.rbacRequireAuth,
        user_search: { default_per_page: current.rbacUserSearchPerPage },
      };
      payload.audit = { retention_days: current.retentionDays };
      payload.metrics = {
        cache_ttl_seconds: current.cacheTtl,
        rbac_denies: { window_days: current.rbacDays },
      };
      payload.ui = { time_format: current.timeFormat };
      payload.evidence = {
        blob_storage_path: current.evidenceBlobPath,
        max_mb: current.evidenceMaxMb,
      };

      return payload;
    }

    const rbacDiffs: NonNullable<SettingsPayload["rbac"]> = {};
    if (current.rbacRequireAuth !== baseline.rbacRequireAuth) {
      rbacDiffs.require_auth = current.rbacRequireAuth;
    }
    if (current.rbacUserSearchPerPage !== baseline.rbacUserSearchPerPage) {
      rbacDiffs.user_search = { default_per_page: current.rbacUserSearchPerPage };
    }
    if (Object.keys(rbacDiffs).length > 0) {
      payload.rbac = rbacDiffs;
    }

    if (current.retentionDays !== baseline.retentionDays) {
      payload.audit = { retention_days: current.retentionDays };
    }

    const metricsDiffs: NonNullable<SettingsPayload["metrics"]> = {};
    if (current.cacheTtl !== baseline.cacheTtl) {
      metricsDiffs.cache_ttl_seconds = current.cacheTtl;
    }
    if (current.rbacDays !== baseline.rbacDays) {
      metricsDiffs.rbac_denies = { window_days: current.rbacDays };
    }
    if (Object.keys(metricsDiffs).length > 0) {
      payload.metrics = metricsDiffs;
    }

    if (current.timeFormat !== baseline.timeFormat) {
      payload.ui = { time_format: current.timeFormat };
    }

    const evidenceDiffs: NonNullable<SettingsPayload["evidence"]> = {};
    if (current.evidenceBlobPath !== baseline.evidenceBlobPath) {
      evidenceDiffs.blob_storage_path = current.evidenceBlobPath;
    }
    if (current.evidenceMaxMb !== baseline.evidenceMaxMb) {
      evidenceDiffs.max_mb = current.evidenceMaxMb;
    }
    if (Object.keys(evidenceDiffs).length > 0) {
      payload.evidence = evidenceDiffs;
    }

    return payload;
  };

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMsg(null);
    try {
      const body = buildPayload();
      const hasChanges = Object.keys(body).some((key) => key !== "apply");
      if (!hasChanges) {
        setMsg("No changes to save.");
        return;
      }

      const res = await apiPut<unknown, SettingsPayload>("/api/admin/settings", body);

      let apiMsg: string | null = null;
      if (res && typeof res === "object") {
        const obj = res as { message?: unknown; note?: unknown };
        if (typeof obj.message === "string") {
          apiMsg = obj.message;
        } else if (obj.note === "stub-only") {
          apiMsg = "Validated. Not persisted (stub).";
        }
      }
      setMsg(apiMsg ?? "Saved.");
      const updated = snapshotFromState();
      snapshotRef.current = updated;
      setEvidenceBlobPath(updated.evidenceBlobPath);
      setRbacDays(updated.rbacDays);
      setEvidenceMaxMb(updated.evidenceMaxMb);
    } catch {
      setMsg("Save failed.");
    } finally {
      setSaving(false);
    }
  }

  async function onPurge(): Promise<void> {
    if (purging) return;
    if (!window.confirm("Purge all evidence records? This cannot be undone.")) return;

    setPurging(true);
    setMsg(null);
    try {
      const res = await apiPost<EvidencePurgeResponse, { confirm: true }>(
        "/api/admin/evidence/purge",
        { confirm: true }
      );

      const deleted = typeof res?.deleted === "number" ? res.deleted : 0;
      if (deleted > 0) {
        setMsg(`Purged ${deleted} evidence item${deleted === 1 ? "" : "s"}.`);
      } else if (res?.note === "evidence-table-missing") {
        setMsg("Evidence table not available.");
      } else {
        setMsg("No evidence to purge.");
      }
    } catch {
      setMsg("Purge failed.");
    } finally {
      setPurging(false);
    }
  }

  return (
    <main id="main" className="container py-3">
      <h1 className="mb-3">Admin Settings</h1>

      {loading ? <p>Loading</p> : (
        <form onSubmit={onSubmit} className="vstack gap-3" aria-label="admin-settings">
          <section className="card">
            <div className="card-header">
              <strong>Authentication</strong>
            </div>
            <div className="card-body vstack gap-3">
              <div className="form-check">
                <input
                  id="rbacRequireAuth"
                  type="checkbox"
                  className="form-check-input"
                  checked={rbacRequireAuth}
                  onChange={(e) => setRbacRequireAuth(e.target.checked)}
                />
                <label htmlFor="rbacRequireAuth" className="form-check-label">
                  Enforce Authentication
                </label>
              </div>

              <div className="row g-2 align-items-end">
                <div className="col-sm-4">
                  <label htmlFor="rbacUserSearchPerPage" className="form-label">User search default per-page</label>
                  <input
                    id="rbacUserSearchPerPage"
                    type="number"
                    min={1}
                    max={500}
                    className="form-control"
                    value={rbacUserSearchPerPage}
                    onChange={(e) =>
                      setRbacUserSearchPerPage(clamp(Number(e.target.value) || 50, 1, 500))
                    }
                  />
                </div>
              </div>
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <strong>Audit</strong>
            </div>
            <div className="card-body">
              <label htmlFor="retentionDays" className="form-label">Retention days</label>
              <input
                id="retentionDays"
                type="number"
                min={1}
                max={730}
                className="form-control"
                value={retentionDays}
                onChange={(e) => setRetentionDays(Math.max(1, Math.min(730, Number(e.target.value) || 1)))}
              />
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <strong>Interface</strong>
            </div>
            <div className="card-body">
              <label htmlFor="timeFormat" className="form-label">Timestamp display</label>
              <select
                id="timeFormat"
                className="form-select"
                value={timeFormat}
                onChange={(e) => setTimeFormat(normalizeTimeFormat(e.target.value))}
              >
                {TIME_FORMAT_OPTIONS.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
              <div className="form-text">
                {TIME_FORMAT_OPTIONS.find((opt) => opt.value === timeFormat)?.example}
              </div>
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <strong>Evidence</strong>
            </div>
            <div className="card-body vstack gap-3">
              <div>
                <label htmlFor="evidenceBlobPath" className="form-label">Blob storage path</label>
                <input
                  id="evidenceBlobPath"
                  type="text"
                  className="form-control"
                  value={evidenceBlobPath}
                  onChange={(e) => setEvidenceBlobPath(e.target.value)}
                  placeholder={blobPathFocused ? "" : blobPlaceholderDefault}
                  autoComplete="off"
                  onFocus={() => setBlobPathFocused(true)}
                  onBlur={() => setBlobPathFocused(false)}
                />
                <div className="form-text">Leave blank to keep storing evidence in the database.</div>
              </div>
              <div className="row g-2 align-items-end">
                <div className="col-sm-4">
                  <label htmlFor="evidenceMaxMb" className="form-label">Maximum file size (MB)</label>
                  <input
                    id="evidenceMaxMb"
                    type="number"
                    min={1}
                    max={4096}
                    className="form-control"
                    value={evidenceMaxMb}
                    onChange={(e) => {
                      const next = Math.trunc(Number(e.target.value) || 1);
                      setEvidenceMaxMb(clamp(next, 1, 4096));
                    }}
                  />
                  <div className="form-text">Files larger than this limit will be rejected.</div>
                </div>
              </div>
              <div className="d-flex flex-column flex-sm-row align-items-sm-center gap-2">
                <button
                  type="button"
                  className="btn btn-outline-danger"
                  onClick={() => void onPurge()}
                  disabled={saving || purging}
                >
                  {purging ? "Purging…" : "Purge evidence"}
                </button>
                <span className="text-muted small">Irreversibly deletes all stored evidence records.</span>
              </div>
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <strong>Metrics</strong>
            </div>
            <div className="card-body vstack gap-3">
              <div className="row g-2 align-items-end">
                <div className="col-sm-4">
                  <label htmlFor="cacheTtl" className="form-label">Cache TTL (seconds)</label>
                  <input
                    id="cacheTtl"
                    type="number"
                    min={0}
                    max={2_592_000}
                    className="form-control"
                    value={cacheTtl}
                    onChange={(e) => setCacheTtl(Math.max(0, Math.min(2_592_000, Number(e.target.value) || 0)))}
                  />
                </div>

                <div className="col-sm-4">
                  <label htmlFor="rbacDays" className="form-label">Authentication window (days)</label>
                  <input
                    id="rbacDays"
                    type="number"
                    className="form-control"
                    value={rbacDays}
                    onChange={(e) => {
                      const next = Math.trunc(Number(e.target.value));
                      setRbacDays(Number.isFinite(next) ? next : 0);
                    }}
                  />
                </div>
              </div>
            </div>
          </section>

          <div className="hstack gap-2">
            <button type="submit" className="btn btn-primary" disabled={saving || purging}>Save</button>
            {msg && <span aria-live="polite" className="text-muted">{msg}</span>}
          </div>
        </form>
      )}
    </main>
  );
}
