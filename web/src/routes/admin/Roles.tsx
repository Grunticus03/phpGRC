import { useEffect, useState } from "react";

export default function Roles(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [roles, setRoles] = useState<string[]>([]);
  const [msg, setMsg] = useState<string | null>(null);
  const [name, setName] = useState<string>("");

  const load = async () => {
    setLoading(true);
    setMsg(null);
    try {
      const res = await fetch("/api/rbac/roles");
      const json = await res.json();
      if (json?.ok && Array.isArray(json.roles)) setRoles(json.roles);
      else setMsg("Failed to load roles.");
    } catch {
      setMsg("Network error.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setMsg(null);
    try {
      const res = await fetch("/api/rbac/roles", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name }),
      });
      const json = await res.json();
      if (res.status === 202 && json?.note === "stub-only") {
        setMsg(`Accepted: "${json.accepted?.name}". Persistence not implemented.`);
        setName("");
      } else if (res.status === 422) {
        setMsg("Validation error. Name must be 2–64 chars.");
      } else if (res.status === 403) {
        setMsg("Forbidden. Admin required.");
      } else if (!json?.ok) {
        setMsg("Request failed.");
      } else {
        setMsg("Unexpected response.");
      }
    } catch {
      setMsg("Network error.");
    }
  };

  if (loading) return <p>Loading…</p>;

  return (
    <div className="container py-3">
      <h1>RBAC Roles</h1>
      {msg && <div className="alert alert-warning">{msg}</div>}

      {roles.length === 0 ? (
        <p>No roles defined.</p>
      ) : (
        <ul className="list-group mb-3">
          {roles.map((r) => (
            <li key={r} className="list-group-item d-flex justify-content-between align-items-center">
              <span>{r}</span>
              <span className="badge bg-secondary">read-only</span>
            </li>
          ))}
        </ul>
      )}

      <form className="card p-3" onSubmit={onSubmit}>
        <div className="mb-2">
          <label htmlFor="roleName" className="form-label">Create role (stub)</label>
          <input
            id="roleName"
            className="form-control"
            value={name}
            maxLength={64}
            onChange={(e) => setName(e.target.value)}
            placeholder="e.g., Compliance Lead"
            required
          />
        </div>
        <button type="submit" className="btn btn-primary" disabled={name.trim().length < 2}>
          Submit
        </button>
        <p className="text-muted mt-2 mb-0">Phase 4 stub. No persistence yet.</p>
      </form>
    </div>
  );
}
