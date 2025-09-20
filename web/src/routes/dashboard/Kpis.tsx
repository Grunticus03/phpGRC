import { useEffect, useState } from "react";
import { fetchKpis, type Kpis } from "../../lib/api/metrics";

function pct(n: number): string {
  // Clamp and format 1 decimal place
  const v = Math.max(0, Math.min(100, n * (n <= 1 ? 100 : 1)));
  return `${v.toFixed(1)}%`;
}

export default function Kpis(): JSX.Element {
  const [data, setData] = useState<Kpis | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const ctrl = new AbortController();
    fetchKpis(ctrl.signal)
      .then(setData)
      .catch((e) => setError(e?.message || "error"));
    return () => ctrl.abort();
  }, []);

  if (error === 'forbidden') {
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

  const d = data.rbac_denies;
  const e = data.evidence_freshness;

  return (
    <section aria-labelledby="kpi-title">
      <h1 id="kpi-title">Dashboard</h1>

      <div className="kpi-grid" style={{ display: "grid", gap: "1rem", gridTemplateColumns: "repeat(auto-fit, minmax(240px, 1fr))" }}>
        <article className="card" aria-labelledby="kpi-denies-title">
          <div className="card-body">
            <h2 id="kpi-denies-title" className="card-title">RBAC Denies Rate</h2>
            <p aria-label="deny-rate" style={{ fontSize: "2rem", margin: 0 }}>{pct(d.rate)}</p>
            <p style={{ margin: 0 }}>
              Window: {d.window_days}d · Denies: {d.denies} / {d.total}
            </p>
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
