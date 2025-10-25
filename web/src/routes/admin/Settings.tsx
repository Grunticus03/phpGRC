import { FormEvent, ReactNode, useCallback, useEffect, useRef, useState } from "react";
import { apiPost, baseHeaders } from "../../lib/api";
import { DEFAULT_TIME_FORMAT, normalizeTimeFormat, type TimeFormat } from "../../lib/formatters";
import "./InlineAccordion.css";

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
  saml?: {
    security?: {
      authnRequestsSigned?: boolean;
      wantAssertionsSigned?: boolean;
      wantAssertionsEncrypted?: boolean;
    };
    sp?: {
      x509cert?: string;
      privateKey?: string;
      privateKeyPath?: string;
      privateKeyPassphrase?: string;
    };
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
  signAuthnRequests: boolean;
  wantAssertionsSigned: boolean;
  wantAssertionsEncrypted: boolean;
  samlPrivateKey: string;
  samlPrivateKeyPath: string;
  samlPrivateKeyPassphrase: string;
  samlCertificate: string;
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
  saml?: {
    security?: {
      authnRequestsSigned?: boolean;
      wantAssertionsSigned?: boolean;
      wantAssertionsEncrypted?: boolean;
    };
    sp?: {
      x509cert?: string;
      privateKey?: string;
      privateKeyPath?: string;
      privateKeyPassphrase?: string;
    };
  };
};

type EvidencePurgeResponse = { ok?: boolean; deleted?: number; note?: string };

const TIME_FORMAT_OPTIONS: Array<{ value: TimeFormat; label: string; example: string }> = [
  { value: "LOCAL", label: "Local date & time", example: "Example: 9/30/2025, 5:23:01 PM" },
  { value: "ISO_8601", label: "ISO 8601 (UTC)", example: "Example: 2025-09-30 21:23:01Z" },
  { value: "RELATIVE", label: "Relative time", example: "Example: 5 minutes ago" },
];


export default function CoreSettings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [readOnly, setReadOnly] = useState(false);

  const [cacheTtl, setCacheTtl] = useState<number>(0);
  const [rbacDays, setRbacDays] = useState<number>(7);
  const [rbacRequireAuth, setRbacRequireAuth] = useState<boolean>(false);
  const [rbacUserSearchPerPage, setRbacUserSearchPerPage] = useState<number>(50);
  const [retentionDays, setRetentionDays] = useState<number>(365);
  const [timeFormat, setTimeFormat] = useState<TimeFormat>(DEFAULT_TIME_FORMAT);
  const [evidenceBlobPath, setEvidenceBlobPath] = useState<string>("");
  const [evidenceMaxMb, setEvidenceMaxMb] = useState<number>(25);
  const [signAuthnRequests, setSignAuthnRequests] = useState(false);
  const [wantAssertionsSigned, setWantAssertionsSigned] = useState(true);
  const [wantAssertionsEncrypted, setWantAssertionsEncrypted] = useState(false);
  const [samlPrivateKey, setSamlPrivateKey] = useState("");
  const [samlPrivateKeyPath, setSamlPrivateKeyPath] = useState("");
  const [samlPrivateKeyPassphrase, setSamlPrivateKeyPassphrase] = useState("");
  const [samlCertificate, setSamlCertificate] = useState("");
  const [samlCertificateAccordionOpen, setSamlCertificateAccordionOpen] = useState(false);
  const [samlPrivateKeyAccordionOpen, setSamlPrivateKeyAccordionOpen] = useState(false);
  const blobPlaceholderDefault = "/opt/phpgrc/shared/blobs";
  const [purging, setPurging] = useState(false);
  const timeFormatExample =
    TIME_FORMAT_OPTIONS.find((opt) => opt.value === timeFormat)?.example ?? null;

  const snapshotRef = useRef<SettingsSnapshot | null>(null);
  const etagRef = useRef<string | null>(null);
  const samlCertificateAutoOpenRef = useRef(true);
  const samlPrivateKeyAutoOpenRef = useRef(true);

  const clamp = (n: number, min: number, max: number) => Math.max(min, Math.min(max, Math.trunc(n)));

  useEffect(() => {
    const trimmed = samlCertificate.trim();
    if (trimmed === "") {
      samlCertificateAutoOpenRef.current = true;
    }

    if (!samlCertificateAccordionOpen && samlCertificateAutoOpenRef.current && trimmed !== "") {
      setSamlCertificateAccordionOpen(true);
    }
  }, [samlCertificate, samlCertificateAccordionOpen]);

  useEffect(() => {
    const trimmed = samlPrivateKey.trim();
    if (trimmed === "") {
      samlPrivateKeyAutoOpenRef.current = true;
    }

    if (!samlPrivateKeyAccordionOpen && samlPrivateKeyAutoOpenRef.current && trimmed !== "") {
      setSamlPrivateKeyAccordionOpen(true);
    }
  }, [samlPrivateKey, samlPrivateKeyAccordionOpen]);

  const toggleSamlCertificateAccordion = useCallback(() => {
    setSamlCertificateAccordionOpen((prev) => {
      const next = !prev;
      if (!next) {
        samlCertificateAutoOpenRef.current = false;
      }
      return next;
    });
  }, []);

  const toggleSamlPrivateKeyAccordion = useCallback(() => {
    setSamlPrivateKeyAccordionOpen((prev) => {
      const next = !prev;
      if (!next) {
        samlPrivateKeyAutoOpenRef.current = false;
      }
      return next;
    });
  }, []);

  const samlCertificatePanelId = "samlCertificate-panel";
  const samlPrivateKeyPanelId = "samlPrivateKey-panel";

  const applyConfig = useCallback(
    (config?: EffectiveConfig | null) => {
      const core = config?.core ?? {};
      const metrics = core.metrics ?? {};
      const rbac = core.rbac ?? {};
      const audit = core.audit ?? {};
      const evidence = core.evidence ?? {};
      const saml = config?.saml ?? {};
      const samlSecurity = saml.security ?? {};
      const samlSp = saml.sp ?? {};

      const nextCacheTtl = clamp(Number(metrics.cache_ttl_seconds ?? 0), 0, 2_592_000);
      const nextRbacDays = clamp(Number(metrics.rbac_denies?.window_days ?? 7), 7, 365);
      const nextRequireAuth = Boolean(rbac.require_auth ?? false);
      const nextPerPage = clamp(Number(rbac.user_search?.default_per_page ?? 50), 1, 500);
      const nextRetention = clamp(Number(audit.retention_days ?? 365), 1, 730);
      const nextTimeFormat = normalizeTimeFormat(core.ui?.time_format);
      const rawBlobPath = typeof evidence?.blob_storage_path === "string" ? evidence.blob_storage_path.trim() : "";
      const nextBlobPath = rawBlobPath === blobPlaceholderDefault ? "" : rawBlobPath;
      const nextMaxMb = clamp(Number(evidence?.max_mb ?? 25), 1, 4096);
      const nextSign = Boolean(samlSecurity?.authnRequestsSigned ?? false);
      const wantSignedRaw = samlSecurity?.wantAssertionsSigned;
      const nextWantSigned =
        typeof wantSignedRaw === "boolean" ? wantSignedRaw : wantSignedRaw == null ? true : Boolean(wantSignedRaw);
      const nextWantEncrypted = Boolean(samlSecurity?.wantAssertionsEncrypted ?? false);
      const nextPrivateKey = typeof samlSp?.privateKey === "string" ? samlSp.privateKey : "";
      const nextPrivateKeyPath = typeof samlSp?.privateKeyPath === "string" ? samlSp.privateKeyPath : "";
      const nextPrivateKeyPassphrase =
        typeof samlSp?.privateKeyPassphrase === "string" ? samlSp.privateKeyPassphrase : "";
      const nextCertificateRaw = typeof samlSp?.x509cert === "string" ? samlSp.x509cert : "";
      const nextCertificate = nextCertificateRaw.trim();

      setCacheTtl(nextCacheTtl);
      setRbacDays(nextRbacDays);
      setRbacRequireAuth(nextRequireAuth);
      setRbacUserSearchPerPage(nextPerPage);
      setRetentionDays(nextRetention);
      setTimeFormat(nextTimeFormat);
      setEvidenceBlobPath(nextBlobPath);
      setEvidenceMaxMb(nextMaxMb);
      setSignAuthnRequests(nextSign);
      setWantAssertionsSigned(nextWantSigned);
      setWantAssertionsEncrypted(nextWantEncrypted);
      setSamlPrivateKey(nextPrivateKey);
      setSamlPrivateKeyPath(nextPrivateKeyPath);
      setSamlPrivateKeyPassphrase(nextPrivateKeyPassphrase);
      setSamlCertificate(nextCertificate);

      snapshotRef.current = {
        cacheTtl: nextCacheTtl,
        rbacDays: nextRbacDays,
        rbacRequireAuth: nextRequireAuth,
        rbacUserSearchPerPage: nextPerPage,
        retentionDays: nextRetention,
        timeFormat: nextTimeFormat,
        evidenceBlobPath: nextBlobPath,
        evidenceMaxMb: nextMaxMb,
        signAuthnRequests: nextSign,
        wantAssertionsSigned: nextWantSigned,
        wantAssertionsEncrypted: nextWantEncrypted,
        samlPrivateKey: nextPrivateKey,
        samlPrivateKeyPath: nextPrivateKeyPath,
        samlPrivateKeyPassphrase: nextPrivateKeyPassphrase,
        samlCertificate: nextCertificate,
      };
    },
    [blobPlaceholderDefault]
  );

  const loadSettings = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch("/admin/settings", {
        method: "GET",
        credentials: "same-origin",
        headers: baseHeaders(),
      });

      if (res.status === 403) {
        setReadOnly(true);
        setMsg("You do not have permission to view settings.");
        snapshotRef.current = null;
        return;
      }

      if (!res.ok) {
        throw new Error(`Failed to load settings (HTTP ${res.status})`);
      }

      const body = (await res.json()) as { config?: EffectiveConfig };
      const etag = res.headers.get("ETag");
      if (etag) {
        etagRef.current = etag;
      }

      setReadOnly(false);
      applyConfig(body?.config ?? null);
    } catch {
      setMsg("Failed to load settings.");
    } finally {
      setLoading(false);
    }
  }, [applyConfig]);

  useEffect(() => {
    setMsg(null);
    void loadSettings();
  }, [loadSettings]);

  const snapshotFromState = (): SettingsSnapshot => ({
    cacheTtl: clamp(Number(cacheTtl) || 0, 0, 2_592_000),
    rbacDays: clamp(Number(rbacDays) || 7, 7, 365),
    rbacRequireAuth,
    rbacUserSearchPerPage: clamp(Number(rbacUserSearchPerPage) || 50, 1, 500),
    retentionDays: clamp(Number(retentionDays) || 365, 1, 730),
    timeFormat,
    evidenceBlobPath: evidenceBlobPath.trim(),
    evidenceMaxMb: clamp(Number(evidenceMaxMb) || 25, 1, 4096),
    signAuthnRequests,
    wantAssertionsSigned,
    wantAssertionsEncrypted,
    samlPrivateKey,
    samlPrivateKeyPath: samlPrivateKeyPath.trim(),
    samlPrivateKeyPassphrase,
    samlCertificate: samlCertificate.trim(),
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
      payload.saml = {
        security: {
          authnRequestsSigned: current.signAuthnRequests,
          wantAssertionsSigned: current.wantAssertionsSigned,
          wantAssertionsEncrypted: current.wantAssertionsEncrypted,
        },
        sp: {
          privateKey: current.samlPrivateKey,
          privateKeyPath: current.samlPrivateKeyPath,
          privateKeyPassphrase: current.samlPrivateKeyPassphrase,
          x509cert: current.samlCertificate,
        },
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

    const samlSecurityDiffs: NonNullable<NonNullable<SettingsPayload["saml"]>["security"]> = {};
    if (current.signAuthnRequests !== baseline.signAuthnRequests) {
      samlSecurityDiffs.authnRequestsSigned = current.signAuthnRequests;
    }
    if (current.wantAssertionsSigned !== baseline.wantAssertionsSigned) {
      samlSecurityDiffs.wantAssertionsSigned = current.wantAssertionsSigned;
    }
    if (current.wantAssertionsEncrypted !== baseline.wantAssertionsEncrypted) {
      samlSecurityDiffs.wantAssertionsEncrypted = current.wantAssertionsEncrypted;
    }

    const samlSpDiffs: NonNullable<NonNullable<SettingsPayload["saml"]>["sp"]> = {};
    if (current.samlCertificate !== baseline.samlCertificate) {
      samlSpDiffs.x509cert = current.samlCertificate;
    }
    if (current.samlPrivateKey !== baseline.samlPrivateKey) {
      samlSpDiffs.privateKey = current.samlPrivateKey;
    }
    if (current.samlPrivateKeyPath !== baseline.samlPrivateKeyPath) {
      samlSpDiffs.privateKeyPath = current.samlPrivateKeyPath;
    }
    if (current.samlPrivateKeyPassphrase !== baseline.samlPrivateKeyPassphrase) {
      samlSpDiffs.privateKeyPassphrase = current.samlPrivateKeyPassphrase;
    }

    if (Object.keys(samlSecurityDiffs).length > 0 || Object.keys(samlSpDiffs).length > 0) {
      payload.saml = {
        ...(payload.saml ?? {}),
        ...(Object.keys(samlSecurityDiffs).length > 0 ? { security: samlSecurityDiffs } : {}),
        ...(Object.keys(samlSpDiffs).length > 0 ? { sp: samlSpDiffs } : {}),
      };
    }

    return payload;
  };

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (readOnly) {
      setMsg("You do not have permission to modify settings.");
      return;
    }
    setSaving(true);
    setMsg(null);
    try {
      const body = buildPayload();
      const hasChanges = payloadHasChanges(body);
      if (!hasChanges) {
        setMsg("No changes to save.");
        setSaving(false);
        return;
      }

      const etag = etagRef.current;
      if (!etag) {
        setMsg("Settings version missing. Reloading...");
        await loadSettings();
        return;
      }

      const res = await fetch("/admin/settings", {
        method: "POST",
        credentials: "same-origin",
        headers: baseHeaders({
          "Content-Type": "application/json",
          "If-Match": etag,
        }),
        body: JSON.stringify(body),
      });

      const resBody = (await parseJson(res)) as {
        ok?: boolean;
        applied?: boolean;
        message?: string;
        note?: string;
        config?: EffectiveConfig;
        etag?: string;
        current_etag?: string;
      } | null;

      if (res.status === 409) {
        const nextEtag = resBody?.current_etag ?? res.headers.get("ETag");
        if (nextEtag) {
          etagRef.current = nextEtag;
        }
        setMsg("Settings changed in another session. Latest values have been reloaded.");
        await loadSettings();
        return;
      }

      if (!res.ok) {
        throw new Error(`Save failed with status ${res.status}`);
      }

      const nextEtag = res.headers.get("ETag") ?? resBody?.etag ?? null;
      if (nextEtag) {
        etagRef.current = nextEtag;
      }

      if (resBody?.config) {
        applyConfig(resBody.config);
      } else {
        const updated = snapshotFromState();
        snapshotRef.current = updated;
        setCacheTtl(updated.cacheTtl);
        setRbacDays(updated.rbacDays);
        setRbacRequireAuth(updated.rbacRequireAuth);
        setRbacUserSearchPerPage(updated.rbacUserSearchPerPage);
        setRetentionDays(updated.retentionDays);
        setTimeFormat(updated.timeFormat);
        setEvidenceBlobPath(updated.evidenceBlobPath);
        setEvidenceMaxMb(updated.evidenceMaxMb);
        setSignAuthnRequests(updated.signAuthnRequests);
        setWantAssertionsSigned(updated.wantAssertionsSigned);
        setWantAssertionsEncrypted(updated.wantAssertionsEncrypted);
        setSamlPrivateKey(updated.samlPrivateKey);
        setSamlPrivateKeyPath(updated.samlPrivateKeyPath);
        setSamlPrivateKeyPassphrase(updated.samlPrivateKeyPassphrase);
        setSamlCertificate(updated.samlCertificate);
      }

      const apiMsg =
        typeof resBody?.message === "string"
          ? resBody.message
          : resBody?.note === "stub-only"
            ? "Validated. Not persisted (stub)."
            : "Saved.";
      setMsg(apiMsg);
    } catch (err) {
      setMsg(err instanceof Error ? err.message : "Save failed.");
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
        "/admin/evidence/purge",
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
    <section className="container py-3">
      <h1 className="mb-3">Core Settings</h1>

      {loading ? <p>Loading</p> : (
        <form onSubmit={onSubmit} className="vstack gap-3" aria-label="core-settings">
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

              <SettingField fieldId="rbacUserSearchPerPage" label="User search default per-page">
                {({ id, describedBy }) => (
                  <input
                    id={id}
                    aria-describedby={describedBy}
                    type="number"
                    min={1}
                    max={500}
                    className="form-control"
                    value={rbacUserSearchPerPage}
                    onChange={(e) =>
                      setRbacUserSearchPerPage(clamp(Number(e.target.value) || 50, 1, 500))
                    }
                  />
                )}
              </SettingField>

              <hr className="my-3" />
              <h2 className="h6 text-uppercase text-muted mb-2">SAML Settings</h2>

              <div className="d-flex flex-column gap-1">
                <div className="form-check form-switch d-flex align-items-center gap-2">
                  <input
                    id="samlSignAuthnRequests"
                    type="checkbox"
                    className="form-check-input"
                    role="switch"
                    checked={signAuthnRequests}
                    onChange={(event) => setSignAuthnRequests(event.target.checked)}
                  />
                  <label htmlFor="samlSignAuthnRequests" className="form-check-label">
                    Sign AuthnRequests
                  </label>
                </div>
                <p className="form-text mb-0">
                  Enable when the Identity Provider requires signed AuthnRequest messages.
                </p>
              </div>

              <div className="d-flex flex-column gap-1">
                <div className="form-check form-switch d-flex align-items-center gap-2">
                  <input
                    id="samlWantAssertionsSigned"
                    type="checkbox"
                    className="form-check-input"
                    role="switch"
                    checked={wantAssertionsSigned}
                    onChange={(event) => setWantAssertionsSigned(event.target.checked)}
                  />
                  <label htmlFor="samlWantAssertionsSigned" className="form-check-label">
                    Require signed responses
                  </label>
                </div>
                <p className="form-text mb-0">Disable only if the Identity Provider cannot sign responses.</p>
              </div>

              <div className="d-flex flex-column gap-1">
                <div className="form-check form-switch d-flex align-items-center gap-2">
                  <input
                    id="samlWantAssertionsEncrypted"
                    type="checkbox"
                    className="form-check-input"
                    role="switch"
                    checked={wantAssertionsEncrypted}
                    onChange={(event) => setWantAssertionsEncrypted(event.target.checked)}
                  />
                  <label htmlFor="samlWantAssertionsEncrypted" className="form-check-label">
                    Require encrypted assertions
                  </label>
                </div>
                <p className="form-text mb-0">
                  Enable when assertions must be encrypted with the configured service provider certificate.
                </p>
              </div>
              <SettingField
                fieldId="samlCertificate"
                label="Signing certificate"
                description="PEM-encoded X.509 certificate that matches the private key and is published in metadata."
                labelAction={
                  <button
                    type="button"
                    className="btn btn-link btn-sm p-0 inline-accordion-toggle"
                    onClick={toggleSamlCertificateAccordion}
                    aria-expanded={samlCertificateAccordionOpen}
                    aria-controls={samlCertificatePanelId}
                    aria-label={`${samlCertificateAccordionOpen ? "Hide" : "Show"} signing certificate editor`}
                  >
                    <i
                      className={`bi ${samlCertificateAccordionOpen ? "bi-caret-up-fill" : "bi-caret-down-fill"}`}
                      aria-hidden="true"
                    />
                  </button>
                }
              >
                {({ id, describedBy }) => (
                  <div
                    id={samlCertificatePanelId}
                    className={`collapse inline-accordion-collapse${samlCertificateAccordionOpen ? " show" : ""}`}
                  >
                    <div className="mt-2">
                      <textarea
                        id={id}
                        aria-describedby={describedBy}
                        className="form-control font-monospace"
                        style={{ resize: "both", minHeight: "8rem", width: "auto", minWidth: "100%" }}
                        value={samlCertificate}
                        onChange={(event) => setSamlCertificate(event.target.value)}
                        placeholder="-----BEGIN CERTIFICATE-----"
                      />
                    </div>
                  </div>
                )}
              </SettingField>

              {signAuthnRequests ? (
                <div className="vstack gap-3">
                  <SettingField
                    fieldId="samlPrivateKey"
                    label="Signing private key"
                    description="Inline PEM-encoded RSA private key used to sign AuthnRequests."
                    help="Leave blank if you plan to load the key from disk via the path below."
                    labelAction={
                      <button
                        type="button"
                        className="btn btn-link btn-sm p-0 inline-accordion-toggle"
                        onClick={toggleSamlPrivateKeyAccordion}
                        aria-expanded={samlPrivateKeyAccordionOpen}
                        aria-controls={samlPrivateKeyPanelId}
                        aria-label={`${samlPrivateKeyAccordionOpen ? "Hide" : "Show"} signing private key editor`}
                      >
                        <i
                          className={`bi ${samlPrivateKeyAccordionOpen ? "bi-caret-up-fill" : "bi-caret-down-fill"}`}
                          aria-hidden="true"
                        />
                      </button>
                    }
                  >
                    {({ id, describedBy }) => (
                      <div
                        id={samlPrivateKeyPanelId}
                        className={`collapse inline-accordion-collapse${samlPrivateKeyAccordionOpen ? " show" : ""}`}
                      >
                        <div className="mt-2">
                          <textarea
                            id={id}
                            aria-describedby={describedBy}
                            className="form-control font-monospace"
                            rows={6}
                            value={samlPrivateKey}
                            onChange={(event) => setSamlPrivateKey(event.target.value)}
                            placeholder="-----BEGIN PRIVATE KEY-----"
                          />
                        </div>
                      </div>
                    )}
                  </SettingField>

                  <SettingField
                    fieldId="samlPrivateKeyPath"
                    label="Private key path"
                    help="Optional absolute path that phpgrc should read for the signing key."
                  >
                    {({ id, describedBy }) => (
                      <input
                        id={id}
                        aria-describedby={describedBy}
                        type="text"
                        className="form-control"
                        value={samlPrivateKeyPath}
                        onChange={(event) => setSamlPrivateKeyPath(event.target.value)}
                        placeholder="/opt/phpgrc/shared/saml/sp.key"
                        autoComplete="off"
                      />
                    )}
                  </SettingField>

                  <SettingField
                    fieldId="samlPrivateKeyPassphrase"
                    label="Private key passphrase"
                    help="Optional passphrase used to decrypt the signing key."
                  >
                    {({ id, describedBy }) => (
                      <input
                        id={id}
                        aria-describedby={describedBy}
                        type="password"
                        className="form-control"
                        value={samlPrivateKeyPassphrase}
                        onChange={(event) => setSamlPrivateKeyPassphrase(event.target.value)}
                        autoComplete="new-password"
                      />
                    )}
                  </SettingField>
                </div>
              ) : null}
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <strong>Audit</strong>
            </div>
            <div className="card-body vstack gap-3">
              <SettingField fieldId="retentionDays" label="Retention days">
                {({ id, describedBy }) => (
                  <input
                    id={id}
                    aria-describedby={describedBy}
                    type="number"
                    min={1}
                    max={730}
                    className="form-control"
                    value={retentionDays}
                    onChange={(e) =>
                      setRetentionDays(Math.max(1, Math.min(730, Number(e.target.value) || 1)))
                    }
                  />
                )}
              </SettingField>
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <strong>Interface</strong>
            </div>
            <div className="card-body vstack gap-3">
              <SettingField
                fieldId="timeFormat"
                label="Timestamp display"
                help={timeFormatExample ?? undefined}
              >
                {({ id, describedBy }) => (
                  <select
                    id={id}
                    aria-describedby={describedBy}
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
                )}
              </SettingField>
            </div>
          </section>

          <section className="card">
            <div className="card-header">
              <strong>Evidence</strong>
            </div>
            <div className="card-body vstack gap-3">
              <SettingField
                fieldId="evidenceBlobPath"
                label="Blob storage path"
                help="Leave blank to keep storing evidence in the database."
              >
                {({ id, describedBy }) => (
                  <input
                    id={id}
                    aria-describedby={describedBy}
                    type="text"
                    className="form-control placeholder-hide-on-focus"
                    value={evidenceBlobPath}
                    onChange={(e) => setEvidenceBlobPath(e.target.value)}
                    placeholder={blobPlaceholderDefault}
                    autoComplete="off"
                    onFocus={(event) => {
                      event.currentTarget.dataset.placeholder = event.currentTarget.placeholder;
                      event.currentTarget.placeholder = "";
                    }}
                    onBlur={(event) => {
                      const original = event.currentTarget.dataset.placeholder || blobPlaceholderDefault;
                      event.currentTarget.placeholder =
                        event.currentTarget.value.trim() === "" ? original : blobPlaceholderDefault;
                    }}
                  />
                )}
              </SettingField>
              <SettingField
                fieldId="evidenceMaxMb"
                label="Maximum file size (MB)"
                help="Files larger than this limit will be rejected."
              >
                {({ id, describedBy }) => (
                  <input
                    id={id}
                    aria-describedby={describedBy}
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
                )}
              </SettingField>
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
              <SettingField
                fieldId="cacheTtl"
                label="Cache TTL (seconds)"
                help="0 disables caching. Max 30 days (2,592,000 seconds)."
              >
                {({ id, describedBy }) => (
                  <input
                    id={id}
                    aria-describedby={describedBy}
                    type="number"
                    min={0}
                    max={2_592_000}
                    className="form-control"
                    value={cacheTtl}
                    onChange={(e) =>
                      setCacheTtl(Math.max(0, Math.min(2_592_000, Number(e.target.value) || 0)))
                    }
                  />
                )}
              </SettingField>

              <SettingField
                fieldId="rbacDays"
                label="Authentication window (days)"
                help="Controls RBAC deny cache. Range: 7–365."
              >
                {({ id, describedBy }) => (
                  <input
                    id={id}
                    aria-describedby={describedBy}
                    type="number"
                    className="form-control"
                    value={rbacDays}
                    onChange={(e) => {
                      const next = Math.trunc(Number(e.target.value));
                      setRbacDays(Number.isFinite(next) ? next : 0);
                    }}
                  />
                )}
              </SettingField>
            </div>
          </section>

          <div className="hstack gap-2">
            <button type="submit" className="btn btn-primary" disabled={saving || purging || readOnly}>
              {saving ? "Saving…" : "Save"}
            </button>
            {msg && <span aria-live="polite" className="text-muted">{msg}</span>}
          </div>
        </form>
      )}
    </section>
  );
}

type SettingFieldProps = {
  fieldId: string;
  label: string;
  description?: ReactNode;
  help?: ReactNode;
  labelAction?: ReactNode;
  children: (attributes: { id: string; describedBy?: string }) => ReactNode;
};

function SettingField({ fieldId, label, description, help, labelAction, children }: SettingFieldProps): JSX.Element {
  const descriptionId = description ? `${fieldId}-description` : undefined;
  const helpId = help ? `${fieldId}-help` : undefined;
  const describedBy = [descriptionId, helpId].filter(Boolean).join(" ") || undefined;

  return (
    <div className="row align-items-start g-3 py-2" data-setting-row>
      <div className="col-lg-5">
        <div className="d-flex align-items-center gap-2 flex-wrap">
          <label htmlFor={fieldId} className="form-label fw-semibold mb-0">
            {label}
          </label>
          {labelAction}
        </div>
        {description ? (
          <div id={descriptionId} className="form-text">
            {description}
          </div>
        ) : null}
      </div>
      <div className="col-lg-4">
        {children({ id: fieldId, describedBy })}
        {help ? (
          <div id={helpId} className="form-text">
            {help}
          </div>
        ) : null}
      </div>
    </div>
  );
}

function payloadHasChanges(body: SettingsPayload): boolean {
  return Object.entries(body).some(([key, value]) => {
    if (key === "apply") return false;
    if (value == null) return false;
    if (typeof value !== "object") return true;
    return Object.keys(value).length > 0;
  });
}

async function parseJson(res: Response): Promise<unknown> {
  try {
    return await res.json();
  } catch {
    return null;
  }
}
