import { useEffect, useState } from "react";
import { listRoles, createRole, CreateRoleResult, RoleListResponse } from "../../lib/api/rbac";

export default function Roles(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [roles, setRoles] = useState<string[]>([]);
  const [note, setNote] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [name, setName] = useState<string>("");
  const [submitting, setSubmitting] = useState<boolean>(false);

  async function load(abort?: AbortSignal) {
    setLoading(true);
    setMsg(null);
    try {
      const res: RoleListResponse = await listRoles();
      if (abort?.aborted) return;
      if (res.ok) {
        setRoles(res.roles);
        setNote(res.note ?? null);
      } else {
        setMsg("Failed to load roles.");
      }
    } catch {
      if (!abort?.aborted) setMsg("Failed to load roles.");
    } finally {
      if (!abort?.aborted) setLoading(false);
    }
  }

  useEffect(() => {
    const ctl = new AbortController();
    void load(ctl.signal);
    return () => ctl.abort();
  }, []);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setMsg(null);
    const trimmed = name.trim();
    if (trimmed.length < 2) return;
    setSubmitting(true);
    try {
      const result: CreateRoleResult = await createRole(trimmed);
      if (result.kind === "created") {
        setMsg(`Created role ${result.roleName} (${result.roleId}).`);
        setName("");
        await load();
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
    } finally {
      setSubmitting(false);
    }
  };

  if (loading)
    return (
      <div className="container py-5" role="status" aria-live="polite" aria-busy="true">
        <div className="spinner-border" aria-hidden="true"></div>
        <span className="visually-hidden">Loading</span>
      </div>
    );

  return (
    <main id="main" className="container py-3" role="main" aria-busy={submitting}>
      <h1 className="mb-3">RBAC Roles</h1>

      <div aria-live="polite" role="status">
        {note && (
          <div className="alert alert-secondary" role="note">
            {note}
          </div>
        )}
        {msg && (
          <div className="alert alert-info" role="alert">
            {msg}
          </div>
        )}
      </div>

      {roles.length === 0 ? (
        <p className="text-muted">No roles defined.</p>
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

      <form className="card p-3" onSubmit={onSubmit} noValidate aria-busy={submitting}>
        <div className="mb-2">
          <label htmlFor="roleName" className="form-label">
            Create role
          </label>
          <input
            id="roleName"
            type="text"
            name="name"
            className="form-control"
            value={name}
            minLength={2}
            maxLength={64}
            onChange={(e) => setName(e.target.value)}
            placeholder="e.g., Compliance Lead"
            required
            autoComplete="off"
            aria-describedby="roleHelp"
          />
          <div id="roleHelp" className="form-text">
            2–64 characters. Letters, numbers, spaces, and dashes recommended.
          </div>
        </div>
        <button type="submit" className="btn btn-primary" disabled={name.trim().length < 2 || submitting}>
          {submitting ? "Submitting…" : "Submit"}
        </button>
        <p className="text-muted mt-2 mb-0">Stub path accepted when RBAC persistence is off.</p>
      </form>
    </main>
  );
}
