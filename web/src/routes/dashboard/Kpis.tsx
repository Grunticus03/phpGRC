import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
  Chart as ChartJS,
  ArcElement,
  BarElement,
  CategoryScale,
  Legend,
  LinearScale,
  Tooltip,
  type ChartOptions,
} from "chart.js";
import { Bar, Pie } from "react-chartjs-2";

import { fetchKpis, type Kpis } from "../../lib/api/metrics";
import { downloadAdminActivityCsv } from "../../lib/api/reports";
import { DEFAULT_TIME_FORMAT, formatTimestamp } from "../../lib/formatters";
import { HttpError } from "../../lib/api";

ChartJS.register(CategoryScale, LinearScale, BarElement, ArcElement, Tooltip, Legend);

const BAR_HEIGHT = 300;
const PIE_HEIGHT = 300;

type ChartDataset = {
  labels: string[];
  success: number[];
  failed: number[];
};

function buildAuthDataset(kpis: Kpis | null): ChartDataset {
  if (!kpis) {
    return { labels: [], success: [], failed: [] };
  }

  const daily = kpis.auth_activity.daily;
  return {
    labels: daily.map((d) => d.date),
    success: daily.map((d) => d.success),
    failed: daily.map((d) => d.failed),
  };
}

export default function Kpis(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [kpis, setKpis] = useState<Kpis | null>(null);
  const [downloadingReport, setDownloadingReport] = useState(false);
  const [reportError, setReportError] = useState<string | null>(null);

  const navigate = useNavigate();

  useEffect(() => {
    let aborted = false;
    const controller = new AbortController();

    const run = async () => {
      try {
        setLoading(true);
        setError(null);
        const data = await fetchKpis(controller.signal);
        if (!aborted) {
          setKpis(data);
        }
      } catch (err: unknown) {
        if (aborted) return;
        if (err instanceof HttpError) {
          if (err.status === 401) setError("You must log in to view KPIs.");
          else if (err.status === 403) setError("You do not have access to KPIs.");
          else setError(`Request failed (HTTP ${err.status}).`);
        } else if (err instanceof Error && err.name === "AbortError") {
          // ignore
        } else {
          setError("Network error.");
        }
        setKpis(null);
      } finally {
        if (!aborted) setLoading(false);
      }
    };

    void run();

    return () => {
      aborted = true;
      controller.abort();
    };
  }, []);

  const authDataset = useMemo(() => buildAuthDataset(kpis), [kpis]);

  const authDays = kpis?.auth_activity.window_days ?? 7;
  const maxDaily = Math.max(0, kpis?.auth_activity.max_daily_total ?? 0);
  const yMax = Math.max(1, maxDaily + 1);

  const authBarOptions: ChartOptions<"bar"> = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      onClick: (_, elements) => {
        if (!kpis || elements.length === 0) return;
        const index = elements[0].index;
        const day = kpis.auth_activity.daily[index];
        if (!day) return;
        const params = new URLSearchParams({
          category: "AUTH",
          occurred_from: day.date,
          occurred_to: day.date,
        });
        navigate(`/admin/audit?${params.toString()}`);
      },
      plugins: {
        legend: { position: "top" },
        tooltip: {
          callbacks: {
            title: (items) => (items[0]?.label ?? ""),
          },
        },
      },
      scales: {
        x: {
          stacked: true,
          title: { display: true, text: "Date" },
        },
        y: {
          stacked: true,
          beginAtZero: true,
          max: yMax,
          ticks: {
            stepSize: Math.max(1, Math.ceil(yMax / 6)),
          },
          title: { display: true, text: "Authentications" },
        },
      },
    }),
    [kpis, navigate, yMax]
  );

  const authBarData = useMemo(
    () => ({
      labels: authDataset.labels.map((iso) => new Date(iso).toLocaleDateString()),
      datasets: [
        {
          label: "Success",
          data: authDataset.success,
          backgroundColor: "#198754",
          borderColor: "#146c43",
          borderWidth: 1,
        },
        {
          label: "Failed",
          data: authDataset.failed,
          backgroundColor: "#dc3545",
          borderColor: "#b02a37",
          borderWidth: 1,
        },
      ],
    }),
    [authDataset]
  );

  const pieSlices = useMemo(
    () => kpis?.evidence_mime.by_mime ?? [],
    [kpis]
  );

  const pieData = useMemo(() => {
    if (pieSlices.length === 0) return null;
    const labels = pieSlices.map((slice) => slice.mime_label ?? slice.mime);
    const counts = pieSlices.map((slice) => slice.count);
    const colors = [
      "#0d6efd",
      "#6f42c1",
      "#20c997",
      "#fd7e14",
      "#6610f2",
      "#ffca2c",
    ];
    return {
      labels,
      datasets: [
        {
          data: counts,
          backgroundColor: labels.map((_, idx) => colors[idx % colors.length]),
          borderColor: "#ffffff",
          borderWidth: 1,
        },
      ],
    };
  }, [pieSlices]);

  const pieOptions: ChartOptions<"pie"> = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: "bottom" },
      },
      onClick: (_, elements) => {
        if (!pieData || elements.length === 0) return;
        const index = elements[0].index;
        const slice = pieSlices[index];
        if (!slice) return;
        const friendly = slice.mime_label ?? slice.mime;
        const params = new URLSearchParams({ mime_label: friendly });
        navigate(`/admin/evidence?${params.toString()}`);
      },
    }),
    [pieData, pieSlices, navigate]
  );

  const auditLink = useMemo(() => {
    const params = new URLSearchParams({ category: "AUTH" });
    return `/admin/audit?${params.toString()}`;
  }, []);

  const evidenceLink = "/admin/evidence";

  const handleDownloadAdminReport = useCallback(async () => {
    if (downloadingReport) return;
    try {
      setDownloadingReport(true);
      setReportError(null);
      await downloadAdminActivityCsv();
    } catch (err: unknown) {
      if (err instanceof HttpError) {
        if (err.status === 401) setReportError("You must log in to download the report.");
        else if (err.status === 403) setReportError("You do not have access to this report.");
        else setReportError(`Download failed (HTTP ${err.status}).`);
      } else {
        setReportError("Network error while downloading report.");
      }
    } finally {
      setDownloadingReport(false);
    }
  }, [downloadingReport]);

  const authTotals = kpis?.auth_activity.totals ?? { success: 0, failed: 0, total: 0 };
  const admins = kpis?.admin_activity.admins ?? [];

  return (
    <div className="container py-3">
      <h1 className="mb-3">Dashboard KPIs</h1>

      {loading && <p>Loading…</p>}
      {!loading && error && <div className="alert alert-warning" role="alert">{error}</div>}

      {!loading && !error && kpis && (
        <div className="vstack gap-3">
          <section className="card">
            <div className="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
              <Link to={auditLink} className="fw-semibold text-decoration-none">
                Authentications last {authDays} days
              </Link>
              <div className="text-muted small">
                <span className="me-3">Success: {authTotals.success}</span>
                <span className="me-3">Failed: {authTotals.failed}</span>
                <span>Total: {authTotals.total}</span>
              </div>
            </div>
            <div className="card-body" style={{ height: BAR_HEIGHT }}>
              {authDataset.labels.length === 0 ? (
                <p className="text-muted mb-0">No authentication activity in the selected window.</p>
              ) : (
                <Bar options={authBarOptions} data={authBarData} aria-label="Authentication activity" />
              )}
            </div>
          </section>

          <div className="row g-3 align-items-stretch">
            <div className="col-12 col-lg-6 d-flex">
              <section className="card h-100 flex-fill">
                <div className="card-header">
                  <Link to={evidenceLink} className="fw-semibold text-decoration-none">
                    Evidence MIME types
                  </Link>
                </div>
                <div className="card-body" style={{ height: PIE_HEIGHT }}>
                  {pieData ? (
                    <Pie data={pieData} options={pieOptions} aria-label="Evidence MIME distribution" />
                  ) : (
                    <p className="text-muted mb-0">No evidence uploads yet.</p>
                  )}
                </div>
              </section>
            </div>

            <div className="col-12 col-lg-6 d-flex">
              <section className="card h-100 flex-fill">
                <div className="card-header">
                  <span className="fw-semibold">Admin Activity</span>
                </div>
                <div className="card-body d-flex flex-column p-0">
                  {reportError && (
                    <div className="alert alert-warning mb-0 rounded-0 py-2 px-3" role="alert">
                      {reportError}
                    </div>
                  )}
                  <div className="flex-grow-1">
                    {admins.length === 0 ? (
                      <p className="text-muted px-3 py-3 mb-0">No admin users found.</p>
                    ) : (
                      <div className="table-responsive">
                        <table className="table table-sm table-striped mb-0">
                          <thead>
                            <tr>
                              <th scope="col">User</th>
                              <th scope="col">Last Login</th>
                            </tr>
                          </thead>
                          <tbody>
                            {admins.map((admin) => {
                              const lastLogin = admin.last_login_at
                                ? formatTimestamp(admin.last_login_at, DEFAULT_TIME_FORMAT)
                                : "—";
                              return (
                                <tr key={`${admin.id}-${admin.email}`}>
                                  <td>
                                    <div className="fw-semibold">{admin.name || admin.email || `User ${admin.id}`}</div>
                                    {admin.email && <div className="text-muted small">{admin.email}</div>}
                                  </td>
                                  <td>{lastLogin}</td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                </div>
                <div className="card-footer text-end bg-transparent border-top">
                  <button
                    type="button"
                    className="btn btn-outline-secondary btn-sm"
                    onClick={handleDownloadAdminReport}
                    disabled={downloadingReport}
                    aria-busy={downloadingReport}
                  >
                    {downloadingReport ? "Downloading…" : "Download CSV"}
                  </button>
                </div>
              </section>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
