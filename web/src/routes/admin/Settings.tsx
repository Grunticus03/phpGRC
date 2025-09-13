import { useEffect, useMemo, useState } from "react";

type CoreConfig = {
  rbac: { enabled: boolean; roles: string[]; require_auth: boolean };
  audit: { enabled: boolean; retention_days: number };
  evidence: { enabled: boolean; max_mb: number; allowed_mime: string[] };
  avatars: { enabled: boolean; size_px: number; format: string };
};

type ApiEnvelope =
  | { ok: true; config?: { core: Partial<CoreConfig> }; note?: string }
  | { ok: false; code: string; errors?: Record<string, string[]> };

function FieldErrors({ msgs }: { msgs: string[] }) {
  if (!msgs || msgs.length === 0) return null;
  return (
    <div className="invalid-feedback d-block" role="alert" aria-live="assertive">
      <ul className="mb-0">
        {msgs.map((m, i) => (
          <li key={i}>{m}</li>
        ))}
      </ul>
    </div>
  );
}

const DEFAULT_CORE: CoreConfig = {
  rbac: { enabled: true, roles: ["Admin", "Auditor", "Risk Manager", "User"], require_auth: false },
  audit: { enabled: true, retention_days: 365 },
  evidence: {
    enabled: true,
    max_mb: 25,
    allowed_mime: ["application/pdf", "image/png", "image/jpeg", "text/plain"],
  },
  avatars: { enabled: true, size_px: 128, format: "webp" },
};

function normalizeCore(incoming?: Partial<CoreConfig>): CoreConfig {
  const src = incoming ?? {};
  return {
    rbac: {
      enabled: src.rbac?.enabled ?? DEFAULT_CORE.rbac.enabled,
      roles: src.rbac?.roles ?? DEFAULT_CORE.rbac.roles,
      require_auth: Boolean(src.rbac?.require_auth ?? DEFAULT_CORE.rbac.require_auth),
    },
    audit: {
      enabled: src.audit?.enabled ?? DEFAULT_CORE.audit.enabled,
      retention_days: Number(src.audit?.retention_days ?? DEFAULT_CORE.audit.retention_days),
    },
    evidence: {
      enabled: src.evidence?.enabled ?? DEFAULT_CORE.evidence.enabled,
      max_mb: Number(src.evidence?.max_mb ?? DEFAULT_CORE.evidence.max_mb),
      allowed_mime: src.evidence?.allowed_mime ?? DEFAULT_CORE.evidence.allowed_mime,
    },
    avatars: {
      enabled: src.avatars?.enabled ?? DEFAULT_CORE.avatars.enabled,
      size_px: Number(src.avatars?.size_px ?? DEFAULT_CORE.avatars.size_px),
      format: src.avatars?.format ?? DEFAULT_CORE.avatars.format,
    },
  };
}

export default function Settings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [core, setCore] = useState<CoreConfig>(DEFAULT_CORE);

  useEffect(() => {
    const ctl = new AbortController();
    (async () => {
      setLoading(true);
      setMsg(null);
      setErrors({});
      try {
        const res = await fetch("/api/admin/settings", {
          credentials: "same-origin",
          signal: ctl.signal,
        });
        const json: ApiEnvelope = await res.json();
        if (!ctl.signal.aborted && res.ok && (json as any)?.ok && (json as any)?.config?.core) {
          const normalized = normalizeCore((json as any).config.core as Partial<CoreConfig>);
          setCore(normalized);
          setMsg(null);
        } else if (!ctl.signal.aborted) {
          setMsg("Failed to load settings.");
        }
      } catch {
        if (!ctl.signal.aborted) setMsg("Network error.");
      } finally {
        if (!ctl.signal.aborted) setLoading(false);
      }
    })();
    return () => ctl.abort();
  }, []);

  const hasErrors = useMemo(() => Object.keys(errors).length > 0, [errors]);

  function err(key: string): string[] {
    return errors[key] ?? [];
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setMsg(null);
    setErrors({});
    setSaving(true);
    try {
      const res = await fetch("/api/admin/settings", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ core }),
      });
      const json: ApiEnvelope = await res.json();

      if (res.status === 422 && json && "code" in json && json.code === "VALIDATION_FAILED") {
        setErrors(json.errors ?? {});
        setMsg("Validation failed.");
      } else if (res.status === 403) {
        setMsg("Forbidden.");
      } else if (res.ok && "ok" in json && (json as any).ok) {
        if ("note" in json && (json as any).note === "stub-only") {
          setMsg("Validated. Not persisted (stub).");
        } else {
          setMsg("Saved.");
        }
      } else {
        setMsg("Request failed.");
      }
    } catch {
      setMsg("Network error.");
    } finally {
      setSaving(false);
    }
  }

  if (loading)
    return (
      <div className="container py-5" role="status" aria-live="polite" aria-busy="true">
        <div className="spinner-border" aria-hidden="true"></div>
        <span className="visually-hidden">Loading</span>
      </div>
    );

  const disabled = saving;

  return (
    <main id="main" className="container py-3" role="main" aria-busy={saving}>
      <h1 className="mb-3">Admin Settings</h1>

      <div aria-live="polite" role="status">
        {msg && <div className="alert alert-info">{msg}</div>}
      </div>

      {hasErrors && (
        <div className="alert alert-warning" role="alert" aria-live="assertive">
          <p className="mb-1">
            <strong>Fix the highlighted fields.</strong>
          </p>
          <ul className="mb-0">
            {Object.entries(errors).map(([k, v]) => (
              <li key={k}>
                {k}: {v?.[0] ?? "Invalid value"}
              </li>
            ))}
          </ul>
        </div>
      )}

      <form onSubmit={onSubmit} noValidate aria-busy={saving}>
        {/* RBAC */}
        <fieldset className="mb-4" disabled={disabled}>
          <legend className="h5">RBAC</legend>

          <div className="form-check">
            <input
              id="rbac_enabled"
              className={"form-check-input" + (err("rbac.enabled").length ? " is-invalid" : "")}
              type="checkbox"
              checked={core.rbac.enabled}
              onChange={(e) =>
                setCore({ ...core, rbac: { ...core.rbac, enabled: e.currentTarget.checked } })
              }
            />
            <label className="form-check-label" htmlFor="rbac_enabled">
              Enable RBAC
            </label>
            <FieldErrors msgs={err("rbac.enabled")} />
          </div>

          <div className="form-check mt-2">
            <input
              id="rbac_require_auth"
              className={"form-check-input" + (err("rbac.require_auth").length ? " is-invalid" : "")}
              type="checkbox"
              checked={core.rbac.require_auth}
              onChange={(e) =>
                setCore({ ...core, rbac: { ...core.rbac, require_auth: e.currentTarget.checked } })
              }
            />
            <label className="form-check-label" htmlFor="rbac_require_auth">
              Require Auth (Sanctum) for RBAC APIs
            </label>
            <FieldErrors msgs={err("rbac.require_auth")} />
          </div>

          <label className="form-label mt-2" htmlFor="rbac_roles">
            Roles (read-only in Phase 4)
          </label>
          <input id="rbac_roles" className="form-control" value={core.rbac.roles.join(", ")} readOnly />
          <FieldErrors msgs={err("rbac.roles")} />
        </fieldset>

        {/* Audit */}
        <fieldset className="mb-4" disabled={disabled}>
          <legend className="h5">Audit</legend>

          <div className="form-check">
            <input
              id="audit_enabled"
              className={"form-check-input" + (err("audit.enabled").length ? " is-invalid" : "")}
              type="checkbox"
              checked={core.audit.enabled}
              onChange={(e) =>
                setCore({ ...core, audit: { ...core.audit, enabled: e.currentTarget.checked } })
              }
            />
            <label className="form-check-label" htmlFor="audit_enabled">
              Enable Audit Trail
            </label>
            <FieldErrors msgs={err("audit.enabled")} />
          </div>

          <label className="form-label mt-2" htmlFor="audit_retention">
            Retention days
          </label>
          <input
            id="audit_retention"
            className={"form-control" + (err("audit.retention_days").length ? " is-invalid" : "")}
            type="number"
            inputMode="numeric"
            min={1}
            max={730}
            step={1}
            value={core.audit.retention_days}
            onChange={(e) =>
              setCore({
                ...core,
                audit: { ...core.audit, retention_days: Number(e.currentTarget.value) || 0 },
              })
            }
          />
          <FieldErrors msgs={err("audit.retention_days")} />
        </fieldset>

        {/* Evidence */}
        <fieldset className="mb-4" disabled={disabled}>
          <legend className="h5">Evidence</legend>

          <div className="form-check">
            <input
              id="evidence_enabled"
              className={"form-check-input" + (err("evidence.enabled").length ? " is-invalid" : "")}
              type="checkbox"
              checked={core.evidence.enabled}
              onChange={(e) =>
                setCore({
                  ...core,
                  evidence: { ...core.evidence, enabled: e.currentTarget.checked },
                })
              }
            />
            <label className="form-check-label" htmlFor="evidence_enabled">
              Enable Evidence
            </label>
            <FieldErrors msgs={err("evidence.enabled")} />
          </div>

          <label className="form-label mt-2" htmlFor="evidence_max_mb">
            Max MB
          </label>
          <input
            id="evidence_max_mb"
            className={"form-control" + (err("evidence.max_mb").length ? " is-invalid" : "")}
            type="number"
            inputMode="numeric"
            min={1}
            max={500}
            step={1}
            value={core.evidence.max_mb}
            onChange={(e) =>
              setCore({
                ...core,
                evidence: { ...core.evidence, max_mb: Number(e.currentTarget.value) || 0 },
              })
            }
          />
          <FieldErrors msgs={err("evidence.max_mb")} />

          <label className="form-label mt-2" htmlFor="evidence_allowed_mime">
            Allowed MIME (comma-separated)
          </label>
          <input
            id="evidence_allowed_mime"
            className={"form-control" + (err("evidence.allowed_mime").length ? " is-invalid" : "")}
            placeholder="application/pdf,image/png,image/jpeg,text/plain"
            value={core.evidence.allowed_mime.join(",")}
            onChange={(e) =>
              setCore({
                ...core,
                evidence: {
                  ...core.evidence,
                  allowed_mime: e.currentTarget.value
                    .split(",")
                    .map((s) => s.trim())
                    .filter(Boolean),
                },
              })
            }
            autoComplete="off"
          />
          <FieldErrors msgs={err("evidence.allowed_mime")} />
        </fieldset>

        {/* Avatars */}
        <fieldset className="mb-4" disabled={disabled}>
          <legend className="h5">Avatars</legend>

          <div className="form-check">
            <input
              id="avatars_enabled"
              className={"form-check-input" + (err("avatars.enabled").length ? " is-invalid" : "")}
              type="checkbox"
              checked={core.avatars.enabled}
              onChange={(e) =>
                setCore({
                  ...core,
                  avatars: { ...core.avatars, enabled: e.currentTarget.checked },
                })
              }
            />
            <label className="form-check-label" htmlFor="avatars_enabled">
              Enable Avatars
            </label>
            <FieldErrors msgs={err("avatars.enabled")} />
          </div>

          <label className="form-label mt-2" htmlFor="avatars_size_px">
            Size (px)
          </label>
          <input
            id="avatars_size_px"
            className={"form-control" + (err("avatars.size_px").length ? " is-invalid" : "")}
            type="number"
            inputMode="numeric"
            min={32}
            max={1024}
            step={1}
            value={core.avatars.size_px}
            onChange={(e) =>
              setCore({
                ...core,
                avatars: { ...core.avatars, size_px: Number(e.currentTarget.value) || 0 },
              })
            }
          />
          <FieldErrors msgs={err("avatars.size_px")} />

          <label className="form-label mt-2" htmlFor="avatars_format">
            Format
          </label>
          <select
            id="avatars_format"
            className={"form-select" + (err("avatars.format").length ? " is-invalid" : "")}
            value={core.avatars.format}
            onChange={(e) =>
              setCore({
                ...core,
                avatars: { ...core.avatars, format: e.currentTarget.value },
              })
            }
          >
            {/* Phase 4 contract: WEBP only. */}
            <option value="webp">webp</option>
          </select>
          <FieldErrors msgs={err("avatars.format")} />
        </fieldset>

        <button className="btn btn-primary" type="submit" disabled={saving}>
          {saving ? "Saving…" : "Save (stub)"}
        </button>
      </form>
    </main>
  );
}
