import { useEffect, useState } from "react";

type CoreConfig = {
  rbac: { enabled: boolean; roles: string[] };
  audit: { enabled: boolean; retention_days: number };
  evidence: { enabled: boolean; max_mb: number; allowed_mime: string[] };
  avatars: { enabled: boolean; size_px: number; format: string };
};

export default function Settings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [msg, setMsg] = useState<string | null>(null);
  const [core, setCore] = useState<CoreConfig>({
    rbac: { enabled: true, roles: ["Admin","Auditor","Risk Manager","User"] },
    audit: { enabled: true, retention_days: 365 },
    evidence: { enabled: true, max_mb: 25, allowed_mime: ["application/pdf","image/png","image/jpeg","text/plain"] },
    avatars: { enabled: true, size_px: 128, format: "webp" },
  });

  useEffect(() => {
    (async () => {
      setLoading(true);
      setMsg(null);
      const res = await fetch("/api/admin/settings");
      const json = await res.json();
      if (json?.ok && json?.config?.core) setCore(json.config.core as CoreConfig);
      setLoading(false);
    })();
  }, []);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setMsg(null);
    const res = await fetch("/api/admin/settings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ core }),
    });
    const json = await res.json();
    if (json?.code === "VALIDATION_FAILED") setMsg("Validation failed.");
    else if (json?.ok && json?.note === "stub-only") setMsg("Validated. Not persisted (stub).");
    else setMsg("Request complete.");
  }

  if (loading) return <p>Loading…</p>;

  return (
    <div className="container py-3">
      <h1>Admin Settings</h1>
      {msg && <div className="alert alert-info">{msg}</div>}

      <form onSubmit={onSubmit}>
        <fieldset className="mb-4">
          <legend>RBAC</legend>
          <label className="form-check">
            <input className="form-check-input" type="checkbox"
              checked={core.rbac.enabled}
              onChange={(e) => setCore({ ...core, rbac: { ...core.rbac, enabled: e.currentTarget.checked } })}
            />
            <span className="form-check-label">Enable RBAC</span>
          </label>
          <label className="form-label mt-2">Roles (read-only in Phase 4)</label>
          <input className="form-control" value={core.rbac.roles.join(", ")} readOnly />
        </fieldset>

        <fieldset className="mb-4">
          <legend>Audit</legend>
          <label className="form-check">
            <input className="form-check-input" type="checkbox"
              checked={core.audit.enabled}
              onChange={(e) => setCore({ ...core, audit: { ...core.audit, enabled: e.currentTarget.checked } })}
            />
            <span className="form-check-label">Enable Audit Trail</span>
          </label>
          <label className="form-label mt-2">Retention days</label>
          <input className="form-control" type="number" min={1} max={730}
            value={core.audit.retention_days}
            onChange={(e) => setCore({ ...core, audit: { ...core.audit, retention_days: Number(e.currentTarget.value) } })}
          />
        </fieldset>

        <fieldset className="mb-4">
          <legend>Evidence</legend>
          <label className="form-check">
            <input className="form-check-input" type="checkbox"
              checked={core.evidence.enabled}
              onChange={(e) => setCore({ ...core, evidence: { ...core.evidence, enabled: e.currentTarget.checked } })}
            />
            <span className="form-check-label">Enable Evidence</span>
          </label>
          <label className="form-label mt-2">Max MB</label>
          <input className="form-control" type="number" min={1} max={500}
            value={core.evidence.max_mb}
            onChange={(e) => setCore({ ...core, evidence: { ...core.evidence, max_mb: Number(e.currentTarget.value) } })}
          />
          <label className="form-label mt-2">Allowed MIME (comma-separated)</label>
          <input className="form-control"
            value={core.evidence.allowed_mime.join(",")}
            onChange={(e) =>
              setCore({
                ...core,
                evidence: {
                  ...core.evidence,
                  allowed_mime: e.currentTarget.value.split(",").map(s => s.trim()).filter(Boolean),
                },
              })
            }
          />
        </fieldset>

        <fieldset className="mb-4">
          <legend>Avatars</legend>
          <label className="form-check">
            <input className="form-check-input" type="checkbox"
              checked={core.avatars.enabled}
              onChange={(e) => setCore({ ...core, avatars: { ...core.avatars, enabled: e.currentTarget.checked } })}
            />
            <span className="form-check-label">Enable Avatars</span>
          </label>
          <label className="form-label mt-2">Size (px)</label>
          <input className="form-control" type="number" min={32} max={1024}
            value={core.avatars.size_px}
            onChange={(e) => setCore({ ...core, avatars: { ...core.avatars, size_px: Number(e.currentTarget.value) } })}
          />
          <label className="form-label mt-2">Format</label>
          <select className="form-select"
            value={core.avatars.format}
            onChange={(e) => setCore({ ...core, avatars: { ...core.avatars, format: e.currentTarget.value } })}
          >
            <option value="webp">webp</option>
            <option value="jpeg">jpeg</option>
            <option value="png">png</option>
          </select>
        </fieldset>

        <button className="btn btn-primary" type="submit">Save (stub)</button>
      </form>
    </div>
  );
}
