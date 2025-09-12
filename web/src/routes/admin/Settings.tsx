import { useEffect, useState } from "react";

type CoreConfig = {
  rbac: { enabled: boolean; roles: string[] };
  audit: { enabled: boolean; retention_days: number };
  evidence: { enabled: boolean; max_mb: number; allowed_mime: string[] };
  avatars: { enabled: boolean; size_px: number; format: string };
};

type ApiEnvelope =
  | { ok: true; config?: { core: CoreConfig }; note?: string }
  | { ok: false; code: string; errors?: Record<string, string[]> };

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
    void (async () => {
      setLoading(true);
      setMsg(null);
      setErrors({});
      try {
        const res = await fetch("/api/admin/settings", { credentials: "same-origin" });
        const json: ApiEnvelope = await res.json();
        if (res.ok && (json as any)?.ok && (json as any)?.config?.core) {
          setCore((json as any).config.core as CoreConfig);
        } else {
          setMsg("Failed to load settings.");
        }
      } catch {
        setMsg("Network error.");
      } finally {
        setLoading(false);
      }
    })();
  }, []);

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

  return (
    <div className="container py-3">
      <h1>Admin Settings</h1>
      {msg && (
        <div className="alert alert-info" role="alert">
          {msg}
        </div>
      )}

      <form onSubmit={onSubmit} noValidate>
        {/* RBAC */}
        <fieldset className="mb-4">
          <legend>RBAC</legend>

          <label className="form-check">
            <input
              className={"form-check-input" + (err("rbac.enabled").length ? " is-invalid" : "")}
              type="checkbox"
              checked={core.rbac.enabled}
              onChange={(e) =>
                setCore({ ...core, rbac: { ...core.rbac, enabled: e.currentTarget.checked } })
              }
            />
            <span className="form-check-label">Enable RBAC</span>
          </label>
          {err("rbac.enabled").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}

          <label className="form-label mt-2">Roles (read-only in Phase 4)</label>
          <input className="form-control" value={core.rbac.roles.join(", ")} readOnly />
          {err("rbac.roles").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}
        </fieldset>

        {/* Audit */}
        <fieldset className="mb-4">
          <legend>Audit</legend>

          <label className="form-check">
            <input
              className={"form-check-input" + (err("audit.enabled").length ? " is-invalid" : "")}
              type="checkbox"
              checked={core.audit.enabled}
              onChange={(e) =>
                setCore({ ...core, audit: { ...core.audit, enabled: e.currentTarget.checked } })
              }
            />
            <span className="form-check-label">Enable Audit Trail</span>
          </label>
          {err("audit.enabled").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}

          <label className="form-label mt-2">Retention days</label>
          <input
            className={"form-control" + (err("audit.retention_days").length ? " is-invalid" : "")}
            type="number"
            min={1}
            max={730}
            value={core.audit.retention_days}
            onChange={(e) =>
              setCore({
                ...core,
                audit: { ...core.audit, retention_days: Number(e.currentTarget.value) },
              })
            }
          />
          {err("audit.retention_days").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}
        </fieldset>

        {/* Evidence */}
        <fieldset className="mb-4">
          <legend>Evidence</legend>

          <label className="form-check">
            <input
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
            <span className="form-check-label">Enable Evidence</span>
          </label>
          {err("evidence.enabled").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}

          <label className="form-label mt-2">Max MB</label>
          <input
            className={"form-control" + (err("evidence.max_mb").length ? " is-invalid" : "")}
            type="number"
            min={1}
            max={500}
            value={core.evidence.max_mb}
            onChange={(e) =>
              setCore({
                ...core,
                evidence: { ...core.evidence, max_mb: Number(e.currentTarget.value) },
              })
            }
          />
          {err("evidence.max_mb").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}

          <label className="form-label mt-2">Allowed MIME (comma-separated)</label>
          <input
            className={
              "form-control" + (err("evidence.allowed_mime").length ? " is-invalid" : "")
            }
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
          {err("evidence.allowed_mime").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}
        </fieldset>

        {/* Avatars */}
        <fieldset className="mb-4">
          <legend>Avatars</legend>

          <label className="form-check">
            <input
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
            <span className="form-check-label">Enable Avatars</span>
          </label>
          {err("avatars.enabled").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}

          <label className="form-label mt-2">Size (px)</label>
          <input
            className={"form-control" + (err("avatars.size_px").length ? " is-invalid" : "")}
            type="number"
            min={32}
            max={1024}
            value={core.avatars.size_px}
            onChange={(e) =>
              setCore({
                ...core,
                avatars: { ...core.avatars, size_px: Number(e.currentTarget.value) },
              })
            }
          />
          {err("avatars.size_px").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}

          <label className="form-label mt-2">Format</label>
          <select
            className={"form-select" + (err("avatars.format").length ? " is-invalid" : "")}
            value={core.avatars.format}
            onChange={(e) =>
              setCore({
                ...core,
                avatars: { ...core.avatars, format: e.currentTarget.value },
              })
            }
          >
            {/* Phase 4 spec allows WEBP. Keep options minimal per contract. */}
            <option value="webp">webp</option>
          </select>
          {err("avatars.format").map((m, i) => (
            <div key={i} className="invalid-feedback d-block">
              {m}
            </div>
          ))}
        </fieldset>

        <button className="btn btn-primary" type="submit" disabled={saving}>
          {saving ? "Saving…" : "Save (stub)"}
        </button>
      </form>
    </div>
  );
}
