/* FILE: web/src/routes/dashboard/Kpis.tsx */
import { useCallback, useEffect, useMemo, useState } from "react";
import { fetchKpis, type Kpis } from "../../lib/api/metrics";
import Sparkline from "../../components/charts/Sparkline";

function pct(n: number): string {
  const v = Math.max(0, Math.min(100, n * (n <= 1 ? 100 : 1)));
  return `${v.toFixed(1)}%`;
}

function clampDays(n: number): number {
  const v = Math.trunc(Number.isFinite(n) ? n : 0);
  return Math.max(1, Math.min(365, v));
}

export default function Kpis(): JSX.Element {
  const [data, setData] = useState<Kpis | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState<boolean>(false);

  // Form state (defaults aligned with server config defaults)
  const [rbacDays, setRbacDays] = useState<number>(7);
  const [freshDays, setFreshDays] = useState<number>(30);

  const canSubmit = useMemo(() => !loading && rbacDays >= 1 && freshDays >= 1, [loading, rbacDays, freshDays]);

  const load = useCallback(async (opts?: { rbac_days?: number; days?: number }) => {
    setLoading(true);
    setError(null);
    try {
      const ctrl = new AbortController();
      const k = await fetchKpis(ctrl.signal, opts);
      setData(k);
      // sync inputs with returned meta-equivalents
      setRbacDays(k.rbac_denies.window_days);
      setFreshDays(k.evidence_freshness.days);
    } catch (e: unknown) {
      setError((e as { message?: string })?.message || "error");
      setData(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  // Safe derived values kept outside conditional returns to keep hook order stable.
  const d = data?.rbac_denies ?? { window_days: 0, denies: 0, total: 0, rate: 0, daily: [] };
  const e = data?.evidence_freshness ?? { days: 0, total: 0, stale: 0, percent: 0, by_mime: [] };
  const sparkValues: number[] = Array.isArray(d.daily) ? d.daily.map((row) => row.rate) : [];

  if (error === "forbidden") {
    return (
      <section aria-labelledby="kpi-title">
        <h1 id="kpi-title">Dashboard</h1>
        <p role="alert">You do not have access to KPIs.</p>
      </section>
    );
  }

  if (error) {
    return (
      <section aria-labelledby="kpi-title">
        <h1 id="kpi-title">Dashboard</h1>
        <p role="alert">Failed to load KPIs: {error}</p>
      </section>
    );
  }

  if (!data) {
    return (
      <section aria-labelledby="kpi-title">
        <h1 id="kpi-title">Dashboard</h1>
        <p>Loading…</p>
      </section>
    );
  }

  return (
    <section aria-labelledby="kpi-title" aria-busy={loading}>
      <h1 id="kpi-title">Dashboard</h1>

      <form
        onSubmit={(ev) => {
          ev.preventDefault();
          void load({ rbac_days: clampDays(rbacDays), days: clampDays(freshDays) });
        }}
        className="card mb-3"
        aria-label="KPI window controls"
        style={{ padding: "0.75rem", display: "grid", gap: "0.75rem", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))" }}
      >
        <div>
          <label htmlFor="rbac_days" className="form-label">RBAC window (days)</label>
          <input
            id="rbac_days"
            type="number"
            min={1}
            max={365}
            step={1}
            className="form-control"
            value={rbacDays}
            onChange={(e) => setRbacDays(clampDays(Number(e.currentTarget.value || 0)))}
          />
        </div>
        <div>
          <label htmlFor="fresh_days" className="form-label">Evidence stale threshold (days)</label>
          <input
            id="fresh_days"
            type="number"
            min={1}
            max={365}
            step={1}
            className="form-control"
            value={freshDays}
            onChange={(e) => setFreshDays(clampDays(Number(e.currentTarget.value || 0)))}
          />
        </div>
        <div style={{ alignSelf: "end" }}>
          <button className="btn btn-primary" type="submit" disabled={!canSubmit} aria-disabled={!canSubmit}>
            {loading ? "Loading…" : "Apply"}
          </button>
        </div>
      </form>

      <div className="kpi-grid" style={{ display: "grid", gap: "1rem", gridTemplateColumns: "repeat(auto-fit, minmax(240px, 1fr))" }}>
        <article className="card" aria-labelledby="kpi-denies-title">
          <div className="card-body">
            <h2 id="kpi-denies-title" className="card-title">RBAC Denies Rate</h2>
            <p aria-label="deny-rate" style={{ fontSize: "2rem", margin: 0 }}>{pct(d.rate)}</p>
            <p style={{ margin: 0 }}>
              Window: {d.window_days}d · Denies: {d.denies} / {d.total}
            </p>
            <div className="mt-2" aria-hidden={sparkValues.length === 0}>
              <Sparkline
                values={sparkValues}
                width={240}
                height={40}
                strokeWidth={2}
                ariaLabel="RBAC denies sparkline"
                className="text-primary"
              />
            </div>
          </div>
        </article>

        <article className="card" aria-labelledby="kpi-evidence-title">
          <div className="card-body">
            <h2 id="kpi-evidence-title" className="card-title">Evidence Freshness</h2>
            <p aria-label="stale-percent" style={{ fontSize: "2rem", margin: 0 }}>{e.percent.toFixed(1)}%</p>
            <p style={{ margin: 0 }}>
              Stale &gt; {e.days}d · {e.stale} of {e.total}
            </p>
          </div>
        </article>
      </div>

      <div style={{ marginTop: "1.5rem" }}>
        <h3>By MIME</h3>
        <table className="table" role="table">
          <thead>
            <tr><th>Type</th><th>Total</th><th>Stale</th><th>% Stale</th></tr>
          </thead>
          <tbody>
            {e.by_mime.map((row) => (
              <tr key={row.mime}>
                <td>{row.mime}</td>
                <td>{row.total}</td>
                <td>{row.stale}</td>
                <td>{row.percent.toFixed(1)}%</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
