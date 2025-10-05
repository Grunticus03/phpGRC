import { FormEvent, useEffect, useMemo, useState } from "react";
import Sparkline from "../../components/charts/Sparkline";
import DaysSelector from "../../components/inputs/DaysSelector";
import { apiGet, HttpError } from "../../lib/api";

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
  percent: number;
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
  return value <= 1 ? value * 100 : value;
}

export default function Kpis(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [msg, setMsg] = useState<string | null>(null);
  const [rbacDays, setRbacDays] = useState<number>(7);
  const [freshDays, setFreshDays] = useState<number>(30);
  const [data, setData] = useState<{ rbac: RbacDenies; fresh: EvidenceFreshness } | null>(null);

  const denyRatePct = useMemo(() => {
    const v = data?.rbac?.rate ?? 0;
    return `${asPercent(v).toFixed(1)}%`;
  }, [data]);

  const stalePct = useMemo(() => {
    const v = data?.fresh?.percent ?? 0;
    return `${asPercent(v).toFixed(1)}%`;
  }, [data]);

  async function load(snapshotParams?: { rbac_days?: number; days?: number }) {
    setLoading(true);
    setMsg(null);
    try {
      const rd = snapshotParams?.rbac_days ?? rbacDays;
      const fd = snapshotParams?.days ?? freshDays;
      const json = await apiGet<Snapshot>("/api/dashboard/kpis", { rbac_days: rd, days: fd });
      if (json?.ok && json.data) {
        const r = json.data.rbac_denies;
        const f = json.data.evidence_freshness;
        setData({ rbac: r, fresh: f });
        const win = json.meta?.window;
        if (typeof win?.rbac_days === "number") setRbacDays(win.rbac_days);
        if (typeof win?.fresh_days === "number") setFreshDays(win.fresh_days);
      } else {
        setMsg("Failed to load KPIs.");
        setData(null);
      }
    } catch (e: unknown) {
      if (e instanceof HttpError) {
        if (e.status === 401) setMsg("You must log in to view KPIs.");
        else if (e.status === 403) setMsg("You do not have access to KPIs.");
        else setMsg(`Request failed (HTTP ${e.status}).`);
      } else {
        setMsg("Network error.");
      }
      setData(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    void load({ rbac_days: rbacDays, days: freshDays });
  }

  return (
    <div className="container py-3">
      <h1 className="mb-3">Dashboard KPIs</h1>

      <form className="row g-3 align-items-end mb-3" onSubmit={onSubmit} aria-label="kpi-overrides">
        <div className="col-auto">
          <DaysSelector
            id="rbacDays"
            label="RBAC window (days)"
            value={rbacDays}
            onChange={setRbacDays}
          />
        </div>
        <div className="col-auto">
          <DaysSelector
            id="freshDays"
            label="Evidence stale threshold (days)"
            value={freshDays}
            onChange={setFreshDays}
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
                  <div className="mt-2">
                    <Sparkline
                      values={(data.rbac.daily ?? []).map(d => d.denies)}
                      ariaLabel="RBAC denies sparkline"
                      width={160}
                      height={24}
                      strokeWidth={1}
                    />
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
          {data.fresh.by_mime.length === 0 ? (
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
                  {data.fresh.by_mime.map((m) => (
                    <tr key={m.mime}>
                      <td>{m.mime}</td>
                      <td className="text-end">{m.total}</td>
                      <td className="text-end">{m.stale}</td>
                      <td className="text-end">{asPercent(m.percent).toFixed(1)}%</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}
