import { useEffect, useState } from "react";
import { listRoles, createRole, CreateRoleResult } from "../../lib/api/rbac";

export default function Roles(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [roles, setRoles] = useState<string[]>([]);
  const [msg, setMsg] = useState<string | null>(null);
  const [name, setName] = useState<string>("");

  const load = async () => {
    setLoading(true);
    setMsg(null);
    const res = await listRoles();
    if (res.ok) setRoles(res.roles);
    else setMsg("Failed to load roles.");
    setLoading(false);
  };

  useEffect(() => {
    void load();
  }, []);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setMsg(null);
    const trimmed = name.trim();
    if (trimmed.length < 2) return;
    const result: CreateRoleResult = await createRole(trimmed);
    if (result.kind === "created") {
      setMsg(`Created role ${result.roleName} (${result.roleId}).`);
      setName("");
      void load();
    } else if (result.kind === "stub") {
      setMsg(`Accepted: "${result.acceptedName}". Persistence not implemented.`);
      setName("");
    } else if (result.kind === "error" && result.code === "FORBIDDEN") {
      setMsg("Forbidden. Admin required.");
    } else if (result.kind === "error" && result.code === "VALIDATION_FAILED") {
      setMsg("Validation error. Name must be 2–64 chars.");
    } else {
      setMsg("Request failed.");
    }
  };

  if (loading) return <p>Loading…</p>;

  return (
    <div className="container py-3">
      <h1>RBAC Roles</h1>
      {msg && <div className="alert alert-warning" role="alert">{msg}</div>}

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
          <label htmlFor="roleName" className="form-label">Create role</label>
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
        <p className="text-muted mt-2 mb-0">Stub path accepted when RBAC persistence is off.</p>
      </form>
    </div>
  );
}
