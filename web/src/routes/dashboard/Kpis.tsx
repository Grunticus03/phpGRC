import { FormEvent, useEffect, useMemo, useState } from "react";

type RbacDaily = { date: string; denies: number; total: number; rate: number };
type RbacDenies = {
  window_days: number;
  from: string;
  to: string;
  denies: number;
  total: number;
  rate: number;
  daily: RbacDaily[];
};

type MimeRow = { mime: string; total: number; stale: number; percent: number };
type EvidenceFreshness = {
  days: number;
  total: number;
  stale: number;
  percent: number; // may be 0..1 or 0..100 depending on backend
  by_mime: MimeRow[];
};

type Snapshot = {
  ok: boolean;
  data?: {
    rbac_denies: RbacDenies;
    evidence_freshness: EvidenceFreshness;
  };
  meta?: {
    generated_at: string;
    window?: { rbac_days?: number; fresh_days?: number };
  };
};

function asPercent(value: number): number {
  if (!isFinite(value)) return 0;
  // Accept both fractional (0..1) and percent (0..100) inputs.
  return value <= 1 ? value * 100 : value;
}

function sparklinePath(values: number[], w = 100, h = 24): string {
  if (values.length === 0) return `M0 ${h} L${w} ${h}`;
  const max = Math.max(...values, 1);
  const step = values.length > 1 ? w / (values.length - 1) : w;
  let d = "";
  values.forEach((v, i) => {
    const x = i * step;
    const y = h - (max === 0 ? 0 : (v / max) * h);
    d += (i === 0 ? "M" : " L") + x.toFixed(2) + " " + y.toFixed(2);
  });
  return d;
}

export default function Kpis(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [msg, setMsg] = useState<string | null>(null);
  const [rbacDays, setRbacDays] = useState<number>(7);
  const [freshDays, setFreshDays] = useState<number>(30);
  const [data, setData] = useState<{ rbac: RbacDenies; fresh: EvidenceFreshness } | null>(null);

  const denyRatePct = useMemo(() => {
    const v = data?.rbac?.rate ?? 0;
    return `${(v * 100).toFixed(1)}%`;
  }, [data]);

  const stalePct = useMemo(() => {
    const v = data?.fresh?.percent ?? 0;
    return `${asPercent(v).toFixed(1)}%`;
  }, [data]);

  const mimeRows = useMemo(() => {
    return (data?.fresh?.by_mime ?? []).map((m) => ({
      ...m,
      _pctDisplay: `${asPercent(m.percent).toFixed(1)}%`,
    }));
  }, [data]);

  async function load(snapshotParams?: { rbac_days?: number; days?: number }) {
    setLoading(true);
    setMsg(null);
    try {
      const qs = new URLSearchParams();
      const rd = snapshotParams?.rbac_days ?? rbacDays;
      const fd = snapshotParams?.days ?? freshDays;
      if (rd) qs.set("rbac_days", String(rd));
      if (fd) qs.set("days", String(fd));
      const res = await fetch(`/api/dashboard/kpis?${qs.toString()}`, { credentials: "same-origin" });
      if (res.status === 401) {
        setMsg("You must log in to view KPIs.");
        setData(null);
      } else if (res.status === 403) {
        setMsg("You do not have access to KPIs.");
        setData(null);
      } else {
        const json = (await res.json()) as Snapshot;
        if (json?.ok && json.data) {
          const r = json.data.rbac_denies;
          const f = json.data.evidence_freshness;
          setData({ rbac: r, fresh: f });
          // Seed form defaults from server once (if provided)
          const win = json.meta?.window;
          if (typeof win?.rbac_days === "number") setRbacDays(win.rbac_days);
          if (typeof win?.fresh_days === "number") setFreshDays(win.fresh_days);
        } else {
          setMsg("Failed to load KPIs.");
          setData(null);
        }
      }
    } catch {
      setMsg("Network error.");
      setData(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    void load({ rbac_days: rbacDays, days: freshDays });
  }

  return (
    <div className="container py-3">
      <h1 className="mb-3">Dashboard KPIs</h1>

      <form className="row g-2 align-items-end mb-3" onSubmit={onSubmit} aria-label="kpi-overrides">
        <div className="col-auto">
          <label htmlFor="rbacDays" className="form-label mb-0">RBAC window (days)</label>
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
        <div className="col-auto">
          <label htmlFor="freshDays" className="form-label mb-0">Evidence stale threshold (days)</label>
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
        <div className="col-auto">
          <button type="submit" className="btn btn-primary">Apply</button>
        </div>
      </form>

      {loading && <p>Loading…</p>}
      {!loading && msg && <div className="alert alert-warning" role="alert">{msg}</div>}

      {!loading && !msg && data && (
        <>
          <div className="row g-3">
            <div className="col-sm-6 col-lg-3">
              <div className="card h-100">
                <div className="card-body">
                  <div className="text-muted small">RBAC deny rate</div>
                  <div aria-label="deny-rate" className="display-6">{denyRatePct}</div>
                  <div className="text-muted small">
                    {data.rbac.denies} denies / {data.rbac.total} total in {data.rbac.window_days}d
                  </div>
                  {/* Sparkline */}
                  <div className="mt-2">
                    <svg
                      aria-label="RBAC denies sparkline"
                      width="100%"
                      height="24"
                      viewBox="0 0 100 24"
                      role="img"
                    >
                      <path
                        d={sparklinePath(data.rbac.daily.map(d => d.denies))}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1"
                      />
                    </svg>
                  </div>
                </div>
              </div>
            </div>

            <div className="col-sm-6 col-lg-3">
              <div className="card h-100">
                <div className="card-body">
                  <div className="text-muted small">Stale evidence</div>
                  <div aria-label="stale-percent" className="display-6">{stalePct}</div>
                  <div className="text-muted small">
                    {data.fresh.stale} / {data.fresh.total} items &gt;{data.fresh.days}d
                  </div>
                </div>
              </div>
            </div>
          </div>

          <h2 className="h5 mt-4">Evidence by MIME</h2>
          {mimeRows.length === 0 ? (
            <p className="text-muted">No evidence.</p>
          ) : (
            <div className="table-responsive">
              <table className="table table-sm table-striped">
                <thead>
                  <tr>
                    <th>MIME</th>
                    <th className="text-end">Total</th>
                    <th className="text-end">Stale</th>
                    <th className="text-end">Percent</th>
                  </tr>
                </thead>
                <tbody>
                  {mimeRows.map((m) => (
                    <tr key={m.mime}>
                      <td>{m.mime}</td>
                      <td className="text-end">{m.total}</td>
                      <td className="text-end">{m.stale}</td>
                      <td className="text-end">{m._pctDisplay}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <p className="text-muted mt-3">Admin-only. Computation bounded by server defaults.</p>
        </>
      )}
    </div>
  );
}
