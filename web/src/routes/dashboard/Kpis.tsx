import { useCallback, useEffect, useMemo, useState } from "react";
import { fetchKpis, type Kpis } from "../../lib/api/metrics";
import { Sparkline } from "../../components/charts/Sparkline";
import DaysSelector from "../../components/inputs/DaysSelector";
import { toSparkPointsFromDenies, clampWindows, windowLabel, sparkAriaLabel } from "../../lib/metrics/transform";

function pct(n: number): string {
  const v = Math.max(0, Math.min(100, n * (n <= 1 ? 100 : 1)));
  return `${v.toFixed(1)}%`;
}

const EMPTY_KPIS: Kpis = {
  rbac_denies: { window_days: 7, from: "", to: "", denies: 0, total: 0, rate: 0, daily: [] },
  evidence_freshness: { days: 30, total: 0, stale: 0, percent: 0, by_mime: [] },
};

export default function Kpis(): JSX.Element {
  const [data, setData] = useState<Kpis | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState<boolean>(false);

  const [rbacDays, setRbacDays] = useState<number>(7);
  const [freshDays, setFreshDays] = useState<number>(30);

  const canSubmit = !loading && rbacDays >= 1 && freshDays >= 1;

  const k = data ?? EMPTY_KPIS;
  const d = k.rbac_denies;
  const e = k.evidence_freshness;

  const sparkPoints = useMemo(() => toSparkPointsFromDenies(d), [d]);
  const aria = useMemo(() => sparkAriaLabel(d), [d]);
  const winLabel = useMemo(() => windowLabel(k), [k]);

  const load = useCallback(async (opts?: { rbac_days?: number; days?: number }) => {
    setLoading(true);
    setError(null);
    try {
      const ctrl = new AbortController();
      const kpis = await fetchKpis(ctrl.signal, opts);
      setData(kpis);
      setRbacDays(kpis.rbac_denies.window_days);
      setFreshDays(kpis.evidence_freshness.days);
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
          const opts = clampWindows({ rbac_days: rbacDays, days: freshDays });
          void load(opts);
        }}
        className="card mb-3"
        aria-label="KPI window controls"
        style={{ padding: "0.75rem", display: "grid", gap: "0.75rem", gridTemplateColumns: "repeat(auto-fit, minmax(260px, 1fr))" }}
      >
        <DaysSelector
          id="rbac_days"
          label="RBAC window (days)"
          value={rbacDays}
          onChange={setRbacDays}
          disabled={loading}
        />
        <DaysSelector
          id="fresh_days"
          label="Evidence stale threshold (days)"
          value={freshDays}
          onChange={setFreshDays}
          disabled={loading}
        />
        <div style={{ alignSelf: "end", display: "flex", gap: 12, alignItems: "center" }}>
          <small aria-live="polite" aria-atomic="true">{winLabel}</small>
          <button className="btn btn-primary" type="submit" disabled={!canSubmit} aria-disabled={!canSubmit}>
            {loading ? "Loading…" : "Apply"}
          </button>
        </div>
      </form>

      <div className="kpi-grid" style={{ display: "grid", gap: "1rem", gridTemplateColumns: "repeat(auto-fit, minmax(260px, 1fr))" }}>
        <article className="card" aria-labelledby="kpi-denies-title">
          <div className="card-body" style={{ display: "grid", gap: 8 }}>
            <h2 id="kpi-denies-title" className="card-title">RBAC Denies Rate</h2>
            <p aria-label="deny-rate" style={{ fontSize: "2rem", margin: 0 }}>{pct(d.rate)}</p>
            <Sparkline data={sparkPoints} width={160} height={36} ariaLabel={aria} />
            <p style={{ margin: 0 }}>
              Window: {d.window_days}d · Denies: {d.denies} / {d.total}
            </p>
          </div>
        </article>

        <article className="card" aria-labelledby="kpi-evidence-title">
          <div className="card-body" style={{ display: "grid", gap: 8 }}>
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
