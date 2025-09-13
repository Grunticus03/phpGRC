import { useEffect, useMemo, useState } from "react";

type CoreConfig = {
  rbac: { enabled: boolean; roles: string[] };
  audit: { enabled: boolean; retention_days: number };
  evidence: { enabled: boolean; max_mb: number; allowed_mime: string[] };
  avatars: { enabled: boolean; size_px: number; format: string };
};

type ApiEnvelope =
  | { ok: true; config?: { core: CoreConfig }; note?: string }
  | { ok: false; code: string; errors?: Record<string, string[]> };

function FieldErrors({ msgs }: { msgs: string[] }) {
  if (!msgs || msgs.length === 0) return null;
  return (
    <>
      {msgs.map((m, i) => (
        <div key={i} className="invalid-feedback d-block">
          {m}
        </div>
      ))}
    </>
  );
}

export default function Settings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [core, setCore] = useState<CoreConfig>({
    rbac: { enabled: true, roles: ["Admin", "Auditor", "Risk Manager", "User"] },
    audit: { enabled: true, retention_days: 365 },
    evidence: {
      enabled: true,
      max_mb: 25,
      allowed_mime: ["application/pdf", "image/png", "image/jpeg", "text/plain"],
    },
    avatars: { enabled: true, size_px: 128, format: "webp" },
  });

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
          setCore((json as any).config.core as CoreConfig);
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

  if (loading) return <p>Loading…</p>;

  const disabled = saving;

  return (
    <div className="container py-3">
      <h1 className="mb-3">Admin Settings</h1>

      <div aria-live="polite">
        {msg && (
          <div className="alert alert-info" role="status">
            {msg}
          </div>
        )}
      </div>

      {hasErrors && (
        <div className="alert alert-warning" role="alert" aria-live="assertive">
          <p className="mb-1"><strong>Fix the highlighted fields.</strong></p>
          <ul className="mb-0">
            {Object.entries(errors).map(([k, v]) => (
              <li key={k}>{k}: {v?.[0] ?? "Invalid value"}</li>
            ))}
          </ul>
        </div>
      )}

      <form onSubmit={onSubmit} noValidate>
        {/* RBAC */}
        <fieldset className="mb-4" disabled={disabled}>
          <legend>RBAC</legend>

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
            <label className="form-check-label" htmlFor="rbac_enabled">Enable RBAC</label>
            <FieldErrors msgs={err("rbac.enabled")} />
          </div>

          <label className="form-label mt-2" htmlFor="rbac_roles">Roles (read-only in Phase 4)</label>
          <input id="rbac_roles" className="form-control" value={core.rbac.roles.join(", ")} readOnly />
          <FieldErrors msgs={err("rbac.roles")} />
        </fieldset>

        {/* Audit */}
        <fieldset className="mb-4" disabled={disabled}>
          <legend>Audit</legend>

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
            <label className="form-check-label" htmlFor="audit_enabled">Enable Audit Trail</label>
            <FieldErrors msgs={err("audit.enabled")} />
          </div>

          <label className="form-label mt-2" htmlFor="audit_retention">Retention days</label>
          <input
            id="audit_retention"
            className={"form-control" + (err("audit.retention_days").length ? " is-invalid" : "")}
            type="number"
            inputMode="numeric"
            min={1}
            max={730}
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
          <legend>Evidence</legend>

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
            <label className="form-check-label" htmlFor="evidence_enabled">Enable Evidence</label>
            <FieldErrors msgs={err("evidence.enabled")} />
          </div>

          <label className="form-label mt-2" htmlFor="evidence_max_mb">Max MB</label>
          <input
            id="evidence_max_mb"
            className={"form-control" + (err("evidence.max_mb").length ? " is-invalid" : "")}
            type="number"
            inputMode="numeric"
            min={1}
            max={500}
            value={core.evidence.max_mb}
            onChange={(e) =>
              setCore({
                ...core,
                evidence: { ...core.evidence, max_mb: Number(e.currentTarget.value) || 0 },
              })
            }
          />
          <FieldErrors msgs={err("evidence.max_mb")} />

          <label className="form-label mt-2" htmlFor="evidence_allowed_mime">Allowed MIME (comma-separated)</label>
          <input
            id="evidence_allowed_mime"
            className={"form-control" + (err("evidence.allowed_mime").length ? " is-invalid" : "")}
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
          />
          <FieldErrors msgs={err("evidence.allowed_mime")} />
        </fieldset>

        {/* Avatars */}
        <fieldset className="mb-4" disabled={disabled}>
          <legend>Avatars</legend>

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
            <label className="form-check-label" htmlFor="avatars_enabled">Enable Avatars</label>
            <FieldErrors msgs={err("avatars.enabled")} />
          </div>

          <label className="form-label mt-2" htmlFor="avatars_size_px">Size (px)</label>
          <input
            id="avatars_size_px"
            className={"form-control" + (err("avatars.size_px").length ? " is-invalid" : "")}
            type="number"
            inputMode="numeric"
            min={32}
            max={1024}
            value={core.avatars.size_px}
            onChange={(e) =>
              setCore({
                ...core,
                avatars: { ...core.avatars, size_px: Number(e.currentTarget.value) || 0 },
              })
            }
          />
          <FieldErrors msgs={err("avatars.size_px")} />

          <label className="form-label mt-2" htmlFor="avatars_format">Format</label>
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
    </div>
  );
}
