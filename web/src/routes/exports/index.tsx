import { useState } from "react";

type Job = { jobId: string; type: string; status: string; progress: number };

export default function ExportsIndex(): JSX.Element {
  const [type, setType] = useState<"csv" | "json" | "pdf">("csv");
  const [jobs, setJobs] = useState<Job[]>([]);
  const [msg, setMsg] = useState<string | null>(null);

  async function createJob(e: React.FormEvent) {
    e.preventDefault();
    setMsg(null);
    const res = await fetch("/api/exports", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ type }),
    });
    const json = await res.json();
    if (json?.ok && json?.jobId) {
      setJobs((prev) => [
        ...prev,
        { jobId: json.jobId, type: json.type, status: "pending", progress: 0 },
      ]);
      setMsg("Job created (stub).");
    } else if (json?.code === "EXPORT_TYPE_INVALID") {
      setMsg("Invalid export type.");
    } else {
      setMsg("Request completed.");
    }
  }

  async function refresh(jobId: string) {
    setMsg(null);
    const res = await fetch(`/api/exports/${encodeURIComponent(jobId)}/status`);
    const json = await res.json();
    if (json?.ok) {
      setJobs((prev) =>
        prev.map((j) => (j.jobId === jobId ? { ...j, status: json.status, progress: json.progress } : j)),
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
          <select className="form-select" value={type} onChange={(e) => setType(e.currentTarget.value as any)}>
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
