import { FormEvent, useEffect, useState } from "react";

type EffectiveConfig = {
  core: {
    metrics?: {
      cache_ttl_seconds?: number;
      evidence_freshness?: { days?: number };
      rbac_denies?: { window_days?: number };
    };
    rbac?: { require_auth?: boolean; user_search?: { default_per_page?: number } };
    audit?: { retention_days?: number };
    evidence?: unknown;
    avatars?: unknown;
  };
};

export default function Settings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const [cacheTtl, setCacheTtl] = useState<number>(0);
  const [freshDays, setFreshDays] = useState<number>(30);
  const [rbacDays, setRbacDays] = useState<number>(7);
  const [rbacRequireAuth, setRbacRequireAuth] = useState<boolean>(false);
  const [rbacUserSearchPerPage, setRbacUserSearchPerPage] = useState<number>(50);
  const [retentionDays, setRetentionDays] = useState<number>(365);

  const [evidenceCfg, setEvidenceCfg] = useState<unknown>(null);
  const [avatarsCfg, setAvatarsCfg] = useState<unknown>(null);

  useEffect(() => {
    void (async () => {
      setLoading(true);
      setMsg(null);
      try {
        const res = await fetch("/api/admin/settings", { credentials: "same-origin" });
        if (!res.ok) throw new Error(String(res.status));
        const json = (await res.json()) as { ok: boolean; config?: EffectiveConfig };
        const core = json?.config?.core ?? {};
        const metrics = core.metrics ?? {};
        const rbac = core.rbac ?? {};
        const audit = core.audit ?? {};
        setCacheTtl(Number(metrics.cache_ttl_seconds ?? 0));
        setFreshDays(Number(metrics.evidence_freshness?.days ?? 30));
        setRbacDays(Number(metrics.rbac_denies?.window_days ?? 7));
        setRbacRequireAuth(Boolean(rbac.require_auth ?? false));
        setRbacUserSearchPerPage(Number(rbac.user_search?.default_per_page ?? 50));
        setRetentionDays(Number(audit.retention_days ?? 365));
        setEvidenceCfg(core.evidence ?? null);
        setAvatarsCfg(core.avatars ?? null);
      } catch {
        setMsg("Failed to load settings.");
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const clamp = (n: number, min: number, max: number) => Math.max(min, Math.min(max, Math.trunc(n)));

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMsg(null);
    try {
      const body = {
        core: {
          metrics: {
            cache_ttl_seconds: clamp(Number(cacheTtl) || 0, 0, 2_592_000),
            evidence_freshness: { days: clamp(Number(freshDays) || 30, 1, 365) },
            rbac_denies: { window_days: clamp(Number(rbacDays) || 7, 1, 365) },
          },
          rbac: {
            require_auth: !!rbacRequireAuth,
            user_search: {
              default_per_page: clamp(Number(rbacUserSearchPerPage) || 50, 1, 500),
            },
          },
          audit: { retention_days: clamp(Number(retentionDays) || 365, 1, 730) },
          evidence: evidenceCfg,
          avatars: avatarsCfg,
        },
        apply: true,
      };

      const res = await fetch("/api/admin/settings", {
        method: "PUT",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });

      if (!res.ok) throw new Error(String(res.status));

      let apiMsg: string | null = null;
      try {
        const data: unknown = await res.json();
        if (typeof data === "object" && data !== null) {
          const obj = data as { message?: unknown; note?: unknown };
          if (typeof obj.message === "string") {
            apiMsg = obj.message;
          } else if (obj.note === "stub-only") {
            apiMsg = "Validated. Not persisted (stub).";
          }
        }
      } catch {
        // ignore parse issues
      }
      setMsg(apiMsg ?? "Saved.");
    } catch {
      setMsg("Save failed.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <main id="main" className="container py-3">
      <h1 className="mb-3">Admin Settings</h1>

      {loading ? <p>Loading</p> : (
        <form onSubmit={onSubmit} className="vstack gap-3" aria-label="admin-settings">
          <section className="card">
            <div className="card-header">
              <strong>RBAC</strong>
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
                  Require Auth (Sanctum) for RBAC APIs
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
                  <div className="form-text">Default page size for Admin → User Roles search. Range 1–500.</div>
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
                  <div className="form-text">0=Disable - Max=30d</div>
                </div>

                <div className="col-sm-4">
                  <label htmlFor="freshDays" className="form-label">Evidence freshness (days)</label>
                  <input
                    id="freshDays"
                    type="number"
                    min={1}
                    max={365}
                    className="form-control"
                    value={freshDays}
                    onChange={(e) => setFreshDays(Math.max(1, Math.min(365, Number(e.target.value) || 1)))}
                  />
                </div>

                <div className="col-sm-4">
                  <label htmlFor="rbacDays" className="form-label">RBAC window (days)</label>
                  <input
                    id="rbacDays"
                    type="number"
                    min={1}
                    max={365}
                    className="form-control"
                    value={rbacDays}
                    onChange={(e) => setRbacDays(Math.max(1, Math.min(365, Number(e.target.value) || 1)))}
                  />
                </div>
              </div>
            </div>
          </section>

          <div className="hstack gap-2">
            <button type="submit" className="btn btn-primary" disabled={saving}>Save</button>
            {msg && <span aria-live="polite" className="text-muted">{msg}</span>}
          </div>
        </form>
      )}
    </main>
  );
}

