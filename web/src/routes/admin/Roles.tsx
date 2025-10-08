import { useEffect, useState } from "react";
import {
  listRoles,
  createRole,
  updateRole,
  deleteRole,
  type CreateRoleResult,
  type UpdateRoleResult,
  type DeleteRoleResult,
  type RoleListResponse,
} from "../../lib/api/rbac";
import { roleOptionsFromList, roleLabelFromId, type RoleOption } from "../../lib/roles";

export default function Roles(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [roles, setRoles] = useState<RoleOption[]>([]);
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
        setRoles(roleOptionsFromList(res.roles ?? []));
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
        const label = roleLabelFromId(result.roleName) ?? result.roleName;
        setMsg(`Created role ${label} (${result.roleId}).`);
        setName("");
        await load();
      } else if (result.kind === "stub") {
        const accepted = result.acceptedName ?? trimmed;
        const label = roleLabelFromId(accepted) ?? accepted;
        setMsg(`Accepted: "${label}". Persistence not implemented.`);
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

  async function renameRole(role: RoleOption) {
    const next = prompt("Rename role", role.name);
    if (next === null) return;
    const trimmed = next.trim();
    if (trimmed.length < 2) {
      setMsg("Role name must be at least 2 characters.");
      return;
    }

    setSubmitting(true);
    setMsg(null);
    try {
      const result: UpdateRoleResult = await updateRole(role.id, trimmed);
      if (result.kind === "updated") {
        const label = roleLabelFromId(result.roleName) ?? result.roleName;
        setMsg(`Renamed to ${label}.`);
        await load();
      } else if (result.kind === "stub") {
        const accepted = result.acceptedName ?? trimmed;
        const label = roleLabelFromId(accepted) ?? accepted;
        setMsg(`Accepted: "${label}". Persistence not implemented.`);
      } else {
        setMsg(result.message ?? result.code ?? "Rename failed.");
      }
    } catch {
      setMsg("Rename failed.");
    } finally {
      setSubmitting(false);
    }
  }

  async function removeRole(role: RoleOption) {
    const label = role.name;
    if (!confirm(`Delete ${label}?`)) return;

    setSubmitting(true);
    setMsg(null);
    try {
      const result: DeleteRoleResult = await deleteRole(role.id);
      if (result.kind === "deleted") {
        setMsg(`${label} deleted.`);
        await load();
      } else if (result.kind === "stub") {
        setMsg("Accepted. Persistence not implemented.");
      } else {
        setMsg(result.message ?? result.code ?? "Delete failed.");
      }
    } catch {
      setMsg("Delete failed.");
    } finally {
      setSubmitting(false);
    }
  }

  if (loading)
    return (
      <div className="container py-5" role="status" aria-live="polite" aria-busy="true">
        <div className="spinner-border" aria-hidden="true"></div>
        <span className="visually-hidden">Loading</span>
      </div>
    );

  return (
    <main id="main" className="container py-3" role="main" aria-busy={submitting}>
      <h1 className="mb-3">Roles Management</h1>

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
        <div className="table-responsive">
          <table className="table table-sm align-middle">
            <thead>
              <tr>
                <th scope="col">Role</th>
                <th scope="col" style={{ width: "14rem" }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {roles.map((role) => (
                <tr key={role.id}>
                  <td>{role.name}</td>
                  <td className="d-flex gap-2">
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm"
                      onClick={() => void renameRole(role)}
                      disabled={submitting}
                    >
                      Rename
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-danger btn-sm"
                      onClick={() => void removeRole(role)}
                      disabled={submitting}
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
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
      </form>
    </main>
  );
}
