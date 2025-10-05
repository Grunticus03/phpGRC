import { useState, type FormEvent } from "react";
import { apiGet, apiPost } from "../../lib/api";

type ExportType = "csv" | "json" | "pdf";
type Job = { jobId: string; type: ExportType; status: string; progress: number };

type CreateExportOk = { ok: true; jobId: string; type?: ExportType };
type CreateExportErr = { code: "EXPORT_TYPE_INVALID" } | Record<string, unknown>;
type CreateExportResponse = CreateExportOk | CreateExportErr;

type StatusOk = { ok: true; status: string; progress: number };
type StatusResponse = StatusOk | Record<string, unknown>;

function isCreateOk(r: CreateExportResponse): r is CreateExportOk {
  return (r as CreateExportOk).ok === true && typeof (r as CreateExportOk).jobId === "string";
}

function isStatusOk(r: StatusResponse): r is StatusOk {
  return (r as StatusOk).ok === true && typeof (r as StatusOk).status === "string";
}

export default function ExportsIndex(): JSX.Element {
  const [type, setType] = useState<ExportType>("csv");
  const [jobs, setJobs] = useState<Job[]>([]);
  const [msg, setMsg] = useState<string | null>(null);

  async function createJob(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setMsg(null);
    try {
      const json = await apiPost<CreateExportResponse, { type: ExportType }>("/api/exports", { type });
      if (isCreateOk(json)) {
        const t: ExportType = json.type ?? type;
        setJobs((prev) => [...prev, { jobId: json.jobId, type: t, status: "pending", progress: 0 }]);
        setMsg("Job created (stub).");
      } else if ((json as CreateExportErr).code === "EXPORT_TYPE_INVALID") {
        setMsg("Invalid export type.");
      } else {
        setMsg("Request completed.");
      }
    } catch {
      setMsg("Network error.");
    }
  }

  async function refresh(jobId: string) {
    setMsg(null);
    try {
      const json = await apiGet<StatusResponse>(`/api/exports/${encodeURIComponent(jobId)}/status`);
      if (isStatusOk(json)) {
        setJobs((prev) =>
          prev.map((job) =>
            job.jobId === jobId
              ? {
                  ...job,
                  status: json.status,
                  progress: Number.isFinite(json.progress) ? json.progress : job.progress,
                }
              : job
          )
        );
      } else {
        setMsg("Failed to refresh job.");
      }
    } catch {
      setMsg("Network error.");
    }
  }

  return (
    <div className="container py-3">
      <h1>Exports</h1>
      {msg && <div className="alert alert-info">{msg}</div>}

      <form onSubmit={createJob} className="row gy-2 gx-2 align-items-end mb-3">
        <div className="col-auto">
          <label className="form-label">Type</label>
          <select
            className="form-select"
            value={type}
            onChange={(e) => setType(e.currentTarget.value as ExportType)}
          >
            <option value="csv">csv</option>
            <option value="json">json</option>
            <option value="pdf">pdf</option>
          </select>
        </div>
        <div className="col-auto">
          <button className="btn btn-primary" type="submit">Create (stub)</button>
        </div>
      </form>

      {jobs.length === 0 ? (
        <p>No jobs yet.</p>
      ) : (
        <div className="table-responsive">
          <table className="table table-sm table-striped">
            <thead>
              <tr>
                <th>Job ID</th>
                <th>Type</th>
                <th>Status</th>
                <th>Progress</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {jobs.map((j) => (
                <tr key={j.jobId}>
                  <td>{j.jobId}</td>
                  <td>{j.type}</td>
                  <td>{j.status}</td>
                  <td>{j.progress}%</td>
                  <td>
                    <button className="btn btn-sm btn-outline-secondary" onClick={() => refresh(j.jobId)}>
                      Refresh
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="text-muted mt-3">Phase 4 stub. Jobs do not run. Downloads are not ready.</p>
    </div>
  );
}
