import { useState, type FormEvent } from "react";

type ExportType = "csv" | "json" | "pdf";
type Job = { jobId: string; type: ExportType; status: string; progress: number };

export default function ExportsIndex(): JSX.Element {
  const [type, setType] = useState<ExportType>("csv");
  const [jobs, setJobs] = useState<Job[]>([]);
  const [msg, setMsg] = useState<string | null>(null);

  async function createJob(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setMsg(null);
    const res = await fetch("/api/exports", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ type }),
    });
    const json: unknown = await res.json();
    const j = (json && typeof json === "object" ? (json as Record<string, unknown>) : null) ?? null;

    if (j?.ok === true && typeof j.jobId === "string") {
      const t: ExportType =
        j.type === "csv" || j.type === "json" || j.type === "pdf" ? (j.type as ExportType) : type;
      setJobs((prev) => [
        ...prev,
        { jobId: j.jobId as string, type: t, status: "pending", progress: 0 },
      ]);
      setMsg("Job created (stub).");
    } else if (j?.code === "EXPORT_TYPE_INVALID") {
      setMsg("Invalid export type.");
    } else {
      setMsg("Request completed.");
    }
  }

  async function refresh(jobId: string) {
    setMsg(null);
    const res = await fetch(`/api/exports/${encodeURIComponent(jobId)}/status`);
    const json: unknown = await res.json();
    const j = (json && typeof json === "object" ? (json as Record<string, unknown>) : null) ?? null;

    if (j?.ok === true) {
      setJobs((prev) =>
        prev.map((job) =>
          job.jobId === jobId
            ? {
                ...job,
                status: typeof j.status === "string" ? j.status : job.status,
                progress: typeof j.progress === "number" ? j.progress : job.progress,
              }
            : job
        )
      );
    } else {
      setMsg("Failed to refresh job.");
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
