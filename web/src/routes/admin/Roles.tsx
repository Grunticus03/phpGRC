import { useEffect, useId, useState } from "react";
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
import { roleOptionsFromList, roleLabelFromId, canonicalRoleId, type RoleOption } from "../../lib/roles";
import ConfirmModal from "../../components/modal/ConfirmModal";

export default function Roles(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [roles, setRoles] = useState<RoleOption[]>([]);
  const [note, setNote] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [name, setName] = useState<string>("");
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [renameTarget, setRenameTarget] = useState<RoleOption | null>(null);
  const [renameValue, setRenameValue] = useState<string>("");
  const [renameError, setRenameError] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<RoleOption | null>(null);

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
        const id = canonicalRoleId(result.roleName);
        const label = roleLabelFromId(id || result.roleName) || result.roleName;
        setMsg(`Created role ${label} (${result.roleId}).`);
        setName("");
        if (id) {
          setRoles((prev) => {
            if (prev.some((role) => role.id === id)) {
              return prev;
            }
            const next = [...prev, { id, name: label }];
            return next.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: "base" }));
          });
        }
        await load();
      } else if (result.kind === "stub") {
        const accepted = result.acceptedName ?? trimmed;
        const label = roleLabelFromId(accepted) ?? accepted;
        setMsg(`Accepted: "${label}". Persistence not implemented.`);
        setName("");
      } else if (result.kind === "error") {
        if (result.code === "FORBIDDEN") {
          setMsg("Forbidden. Admin required.");
        } else if (result.code === "VALIDATION_FAILED") {
          setMsg(result.message ?? "Validation error.");
        } else if (result.message) {
          setMsg(result.message);
        } else if (result.code === "NETWORK_ERROR") {
          setMsg("Network error. Please retry.");
        } else {
          setMsg("Request failed.");
        }
      }
    } finally {
      setSubmitting(false);
    }
  };

  const renameInputId = useId();

  const openRenameModal = (role: RoleOption) => {
    if (submitting) return;
    setRenameTarget(role);
    setRenameValue(role.name);
    setRenameError(null);
    setMsg(null);
  };

  const closeRenameModal = () => {
    if (submitting) return;
    setRenameTarget(null);
    setRenameValue("");
    setRenameError(null);
  };

  const handleConfirmRename = async () => {
    if (!renameTarget) return;
    const trimmed = renameValue.trim();
    if (trimmed.length < 2 || trimmed.length > 64) {
      setRenameError("Role name must be 2–64 characters.");
      return;
    }

    setSubmitting(true);
    setMsg(null);
    setRenameError(null);
    try {
      const result: UpdateRoleResult = await updateRole(renameTarget.id, trimmed);
      if (result.kind === "updated") {
        const label = roleLabelFromId(result.roleName) ?? result.roleName;
        setMsg(`Renamed to ${label}.`);
        await load();
        closeRenameModal();
      } else if (result.kind === "stub") {
        const accepted = result.acceptedName ?? trimmed;
        const label = roleLabelFromId(accepted) ?? accepted;
        setMsg(`Accepted: "${label}". Persistence not implemented.`);
        closeRenameModal();
      } else {
        setRenameError(result.message ?? result.code ?? "Rename failed.");
      }
    } catch {
      setRenameError("Rename failed.");
    } finally {
      setSubmitting(false);
    }
  };

  const openDeleteModal = (role: RoleOption) => {
    if (submitting) return;
    setDeleteTarget(role);
    setMsg(null);
  };

  const closeDeleteModal = () => {
    if (submitting) return;
    setDeleteTarget(null);
  };

  const handleConfirmDelete = async () => {
    if (!deleteTarget) return;
    const label = deleteTarget.name;

    setSubmitting(true);
    setMsg(null);
    try {
      const result: DeleteRoleResult = await deleteRole(deleteTarget.id);
      if (result.kind === "deleted") {
        setMsg(`${label} deleted.`);
        await load();
        closeDeleteModal();
      } else if (result.kind === "stub") {
        setMsg("Accepted. Persistence not implemented.");
        closeDeleteModal();
      } else {
        setMsg(result.message ?? result.code ?? "Delete failed.");
      }
    } catch {
      setMsg("Delete failed.");
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
    <section className="container py-3" aria-busy={submitting}>
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
                  <td>
                    <button
                      type="button"
                      className="w-100 text-start border-0 bg-transparent p-0 role-name-trigger"
                      onClick={() => openRenameModal(role)}
                      aria-label={`Rename ${role.name}`}
                      disabled={submitting}
                    >
                      {role.name}
                    </button>
                  </td>
                  <td className="d-flex gap-2">
                    <button
                      type="button"
                      className="btn btn-outline-danger btn-sm"
                      onClick={() => openDeleteModal(role)}
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
            2–64 characters; alphanumeric, space, and hyphen characters.
          </div>
        </div>
        <button type="submit" className="btn btn-primary" disabled={name.trim().length < 2 || submitting}>
          {submitting ? "Submitting…" : "Submit"}
        </button>
      </form>

      <ConfirmModal
        open={renameTarget !== null}
        title={renameTarget ? `Rename ${renameTarget.name}` : "Rename role"}
        confirmLabel={submitting ? "Renaming…" : "Rename"}
        busy={submitting}
        onConfirm={handleConfirmRename}
        onCancel={closeRenameModal}
        disableBackdropClose={submitting}
      >
        <div className="mb-3">
          <label htmlFor={renameInputId} className="form-label">
            Role name
          </label>
          <input
            id={renameInputId}
            type="text"
            className="form-control"
            value={renameValue}
            onChange={(event) => setRenameValue(event.target.value)}
            maxLength={64}
            minLength={2}
            autoComplete="off"
            disabled={submitting}
            autoFocus
          />
        </div>
        <div className="text-muted small mb-2">2–64 characters; alphanumeric, space, and hyphen characters.</div>
        {renameError && <div className="text-danger small">{renameError}</div>}
      </ConfirmModal>

      <ConfirmModal
        open={deleteTarget !== null}
        title={deleteTarget ? `Delete ${deleteTarget.name}?` : "Delete role"}
        confirmLabel={submitting ? "Deleting…" : "Delete"}
        confirmTone="danger"
        busy={submitting}
        onConfirm={handleConfirmDelete}
        onCancel={closeDeleteModal}
        disableBackdropClose={submitting}
      >
        <p className="mb-0">This action cannot be undone.</p>
      </ConfirmModal>
    </section>
  );
}
