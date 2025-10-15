import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import {
  listRoles,
  createRole,
  updateRole,
  deleteRole,
  listPolicyAssignments,
  getRolePolicies,
  updateRolePolicies,
  type CreateRoleResult,
  type UpdateRoleResult,
  type DeleteRoleResult,
  type RoleListResponse,
  type PolicyListResponse,
  type PolicyAssignment,
  type PolicyListMeta,
  type RolePolicyMeta,
  type RolePolicyResult,
  type RolePolicyUpdateResult,
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
  const [policyAssignments, setPolicyAssignments] = useState<PolicyAssignment[]>([]);
  const [policyMeta, setPolicyMeta] = useState<PolicyListMeta | undefined>(undefined);
  const [policyLoading, setPolicyLoading] = useState<boolean>(true);
  const [policyError, setPolicyError] = useState<string | null>(null);
  const [selectedRole, setSelectedRole] = useState<RoleOption | null>(null);
  const [selectedPolicies, setSelectedPolicies] = useState<string[]>([]);
  const [savedPolicies, setSavedPolicies] = useState<string[]>([]);
  const [rolePoliciesMeta, setRolePoliciesMeta] = useState<RolePolicyMeta | undefined>(undefined);
  const [rolePoliciesLoading, setRolePoliciesLoading] = useState<boolean>(false);
  const [rolePoliciesError, setRolePoliciesError] = useState<string | null>(null);
  const [rolePoliciesMessage, setRolePoliciesMessage] = useState<string | null>(null);

  const rolePolicyRequestRef = useRef(0);

  const load = useCallback(async (abort?: AbortSignal) => {
    setLoading(true);
    setMsg(null);
    try {
      const res: RoleListResponse = await listRoles();
      if (abort?.aborted) return;
      if (res.ok) {
        const options = roleOptionsFromList(res.roles ?? []);
        setRoles(options);
        setNote(res.note ?? null);

        if (selectedRole) {
          const match = options.find((option) => option.id === selectedRole.id);
          if (match) {
            if (match.name !== selectedRole.name) {
              setSelectedRole(match);
            }
          } else {
            rolePolicyRequestRef.current += 1;
            setSelectedRole(null);
            setSelectedPolicies([]);
            setSavedPolicies([]);
            setRolePoliciesMeta(undefined);
            setRolePoliciesError(null);
            setRolePoliciesMessage(null);
          }
        }
      } else {
        setMsg("Failed to load roles.");
      }
    } catch {
      if (!abort?.aborted) setMsg("Failed to load roles.");
    } finally {
      if (!abort?.aborted) setLoading(false);
    }
  }, [selectedRole]);

  const loadPolicies = useCallback(async (abort?: AbortSignal) => {
    setPolicyLoading(true);
    setPolicyError(null);
    try {
      const res: PolicyListResponse = await listPolicyAssignments();
      if (abort?.aborted) return;
      if (res.ok) {
        setPolicyAssignments(res.policies);
        setPolicyMeta(res.meta);
        setPolicyError(null);
      } else {
        setPolicyAssignments([]);
        setPolicyMeta(undefined);
        setPolicyError(res.message ?? res.note ?? res.code ?? "Failed to load permissions.");
      }
    } catch {
      if (!abort?.aborted) {
        setPolicyAssignments([]);
        setPolicyMeta(undefined);
        setPolicyError("Failed to load permissions.");
      }
    } finally {
      if (!abort?.aborted) setPolicyLoading(false);
    }
  }, []);

  useEffect(() => {
    const ctl = new AbortController();
    void load(ctl.signal);
    return () => ctl.abort();
  }, [load]);

  useEffect(() => {
    const ctl = new AbortController();
    void loadPolicies(ctl.signal);
    return () => ctl.abort();
  }, [loadPolicies]);

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

  const policyCountByRole = useMemo(() => {
    const map = new Map<string, number>();
    for (const assignment of policyAssignments) {
      for (const role of assignment.roles) {
        map.set(role, (map.get(role) ?? 0) + 1);
      }
    }
    return map;
  }, [policyAssignments]);

  const sortedPolicies = useMemo(() => {
    return [...policyAssignments].sort((a, b) => {
      const left = a.label ?? a.policy;
      const right = b.label ?? b.policy;
      return left.localeCompare(right, undefined, { sensitivity: "base" });
    });
  }, [policyAssignments]);

  const hasChanges = useMemo(() => {
    if (selectedPolicies.length !== savedPolicies.length) return true;
    const a = [...selectedPolicies].sort((x, y) =>
      x.localeCompare(y, undefined, { sensitivity: "base" })
    );
    const b = [...savedPolicies].sort((x, y) =>
      x.localeCompare(y, undefined, { sensitivity: "base" })
    );
    for (let i = 0; i < a.length; i += 1) {
      if (a[i] !== b[i]) return true;
    }
    return false;
  }, [selectedPolicies, savedPolicies]);

  const canEditPolicies = rolePoliciesMeta?.assignable !== false;

  const renameInputId = useId();

  const closePermissions = () => {
    rolePolicyRequestRef.current += 1;
    setSelectedRole(null);
    setSelectedPolicies([]);
    setSavedPolicies([]);
    setRolePoliciesMeta(undefined);
    setRolePoliciesError(null);
    setRolePoliciesMessage(null);
    setRolePoliciesLoading(false);
  };

  const openPermissions = (role: RoleOption) => {
    setSelectedRole(role);
    setRolePoliciesMessage(null);
    setRolePoliciesError(null);
    setRolePoliciesMeta(undefined);

    const derived = policyAssignments
      .filter((assignment) => assignment.roles.includes(role.id))
      .map((assignment) => assignment.policy);
    const base = Array.from(new Set(derived)).sort((a, b) =>
      a.localeCompare(b, undefined, { sensitivity: "base" })
    );

    setSelectedPolicies(base);
    setSavedPolicies(base);

    const requestId = rolePolicyRequestRef.current + 1;
    rolePolicyRequestRef.current = requestId;
    setRolePoliciesLoading(true);

    void getRolePolicies(role.id).then((result: RolePolicyResult) => {
      if (rolePolicyRequestRef.current !== requestId) {
        return;
      }

      if (result.ok) {
        const sorted = [...result.policies].sort((a, b) =>
          a.localeCompare(b, undefined, { sensitivity: "base" })
        );
        setSelectedPolicies(sorted);
        setSavedPolicies(sorted);
        setRolePoliciesMeta(result.meta);
        setRolePoliciesError(null);
      } else {
        setRolePoliciesError(result.message ?? result.code ?? "Failed to load permissions.");
      }

      setRolePoliciesLoading(false);
    });
  };

  const togglePolicy = (policyKey: string) => {
    setSelectedPolicies((prev) => {
      if (prev.includes(policyKey)) {
        return prev.filter((value) => value !== policyKey);
      }
      return [...prev, policyKey].sort((a, b) =>
        a.localeCompare(b, undefined, { sensitivity: "base" })
      );
    });
  };

  const handleSavePolicies = async () => {
    if (!selectedRole) return;
    setRolePoliciesLoading(true);
    setRolePoliciesError(null);
    setRolePoliciesMessage(null);

    try {
      const result: RolePolicyUpdateResult = await updateRolePolicies(selectedRole.id, selectedPolicies);
      if ("note" in result) {
        const sorted = [...selectedPolicies].sort((a, b) =>
          a.localeCompare(b, undefined, { sensitivity: "base" })
        );
        setSavedPolicies(sorted);
        setRolePoliciesMessage("Stub mode: permissions accepted but not persisted.");
      } else if (result.ok) {
        const sorted = [...result.policies].sort((a, b) =>
          a.localeCompare(b, undefined, { sensitivity: "base" })
        );
        setSelectedPolicies(sorted);
        setSavedPolicies(sorted);
        setRolePoliciesMeta(result.meta);
        setRolePoliciesMessage(`Updated permissions for ${selectedRole.name}.`);
        setPolicyAssignments((prev) =>
          prev.map((assignment) => {
            const roles = assignment.roles.filter((roleId) => roleId !== selectedRole.id);
            if (sorted.includes(assignment.policy)) {
              roles.push(selectedRole.id);
            }
            roles.sort((a, b) => a.localeCompare(b, undefined, { sensitivity: "base" }));
            return { ...assignment, roles };
          })
        );
      } else {
        setRolePoliciesError(result.message ?? result.code ?? "Failed to update permissions.");
      }
    } catch {
      setRolePoliciesError("Failed to update permissions.");
    } finally {
      setRolePoliciesLoading(false);
    }
  };

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
    if (selectedRole && selectedRole.id === deleteTarget.id) {
      closePermissions();
    }

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
        {policyError && (
          <div className="alert alert-warning" role="alert">
            {policyError}
          </div>
        )}
        {policyMeta?.mode && policyMeta.mode !== "persist" && (
          <div className="alert alert-warning" role="note">
            Policies run in {policyMeta.mode} mode; assignments may not persist.
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
                <th scope="col" style={{ width: "10rem" }}>Policies</th>
                <th scope="col" style={{ width: "18rem" }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {roles.map((role) => {
                const count = policyCountByRole.get(role.id) ?? 0;
                const isActive = selectedRole?.id === role.id;
                return (
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
                  <td>
                    {policyLoading ? (
                      <span className="text-muted">Loading…</span>
                    ) : policyAssignments.length === 0 ? (
                      <span className="text-muted">0 policies</span>
                    ) : (
                      <span>
                        {count} {count === 1 ? "policy" : "policies"}
                      </span>
                    )}
                  </td>
                  <td className="d-flex gap-2">
                    <button
                      type="button"
                      className={`btn btn-sm ${
                        isActive ? "btn-primary" : "btn-outline-primary"
                      }`}
                      onClick={() => (isActive ? closePermissions() : openPermissions(role))}
                      disabled={submitting}
                      aria-pressed={isActive}
                    >
                      Manage permissions
                    </button>
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
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {roles.length > 0 && (
        <section className="mt-4" aria-live="polite">
          {selectedRole ? (
            <div className="card">
              <div className="card-header d-flex justify-content-between align-items-center">
                <h2 className="h5 mb-0">Permissions for {selectedRole.name}</h2>
                <button
                  type="button"
                  className="btn btn-link btn-sm text-decoration-none"
                  onClick={closePermissions}
                  disabled={rolePoliciesLoading}
                >
                  Close
                </button>
              </div>
              <div className="card-body">
                {rolePoliciesMessage && (
                  <div className="alert alert-success py-2" role="status">
                    {rolePoliciesMessage}
                  </div>
                )}
                {rolePoliciesError && (
                  <div className="alert alert-danger py-2" role="alert">
                    {rolePoliciesError}
                  </div>
                )}
                {rolePoliciesLoading && (
                  <div className="d-flex align-items-center gap-2 text-muted small mb-3">
                    <span className="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <span>Loading permissions…</span>
                  </div>
                )}
                {sortedPolicies.length === 0 && !policyLoading ? (
                  <p className="text-muted mb-0">No policies defined.</p>
                ) : (
                  <div className="d-flex flex-column gap-3">
                    {sortedPolicies.map((policy) => {
                      const checkboxId = `policy-${policy.policy.replace(/[^a-zA-Z0-9_-]+/g, "_")}`;
                      const checked = selectedPolicies.includes(policy.policy);
                      return (
                        <div className="form-check" key={policy.policy}>
                          <input
                            className="form-check-input"
                            type="checkbox"
                            id={checkboxId}
                            checked={checked}
                            onChange={() => togglePolicy(policy.policy)}
                            disabled={rolePoliciesLoading || !canEditPolicies}
                          />
                          <label className="form-check-label" htmlFor={checkboxId}>
                            <span className="fw-semibold">{policy.label ?? policy.policy}</span>
                            <span className="d-block text-muted small">
                              {policy.description ?? policy.policy}
                            </span>
                          </label>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
              <div className="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div className="small text-muted">
                  {canEditPolicies
                    ? "Changes take effect immediately after saving."
                    : "Persistence is disabled; assignments are read-only."}
                </div>
                <div className="d-flex gap-2">
                  <button
                    type="button"
                    className="btn btn-outline-secondary btn-sm"
                    onClick={closePermissions}
                    disabled={rolePoliciesLoading}
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    className="btn btn-primary btn-sm"
                    onClick={handleSavePolicies}
                    disabled={rolePoliciesLoading || !canEditPolicies || !hasChanges}
                  >
                    {rolePoliciesLoading ? "Saving…" : "Save changes"}
                  </button>
                </div>
              </div>
            </div>
          ) : (
            <p className="text-muted mb-0">Select a role to manage permissions.</p>
          )}
        </section>
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
