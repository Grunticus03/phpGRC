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
import "./Roles.css";

type PolicyCopy = {
  label: string;
  description: string;
};

type PolicyGroupConfig = {
  title: string;
  key?: string;
};

type PolicyGroupMeta = {
  key: string;
  title: string;
};

const POLICY_GROUP_CONFIG: Record<string, PolicyGroupConfig> = {
  "core.audit": { title: "Audit" },
  "core.evidence": { title: "Evidence" },
  "core.exports": { title: "Exports" },
  "core.metrics": { title: "Metrics" },
  "core.reports": { title: "Reports" },
  "core.rbac": { title: "Access Control", key: "rbac" },
  "core.settings": { title: "Core Settings" },
  "core.users": { title: "User Management" },
  core: { title: "Core" },
  "rbac.roles": { title: "Role Management" },
  "rbac.user_roles": { title: "User Assignments" },
  rbac: { title: "Access Control" },
  "ui.brand": { title: "Branding" },
  "ui.nav.sidebar": { title: "Navigation" },
  "ui.theme": { title: "Theme" },
  ui: { title: "User Interface" },
};

const POLICY_GROUP_ACRONYMS = new Set(["ui", "rbac", "api", "mfa"]);

const THEME_POLICY_COPY: Record<string, PolicyCopy> = {
  "ui.theme.view": {
    label: "View theme settings",
    description: "Read-only access to theme configuration and branding assets.",
  },
  "ui.theme.manage": {
    label: "Manage theme settings",
    description: "Allows editing theme configuration and branding assets.",
  },
  "ui.theme.pack.manage": {
    label: "Manage theme packs",
    description: "Import, update, and delete theme pack archives.",
  },
};

function humanizeThemePolicyKey(key: string): string {
  const raw = key.replace(/^ui\.theme\./, "").replace(/[._]/g, " ").trim();
  if (raw === "") return "Theme access";
  return raw.replace(/\b\w/g, (char) => char.toUpperCase());
}

function getPolicyDisplay(policy: PolicyAssignment): PolicyCopy {
  if (policy.policy.startsWith("ui.theme.")) {
    const override = THEME_POLICY_COPY[policy.policy];
    const trimmedLabel = policy.label?.trim() ?? "";
    const trimmedDescription = policy.description?.trim() ?? "";
    const label =
      override?.label ??
      (trimmedLabel !== "" && trimmedLabel !== policy.policy ? trimmedLabel : humanizeThemePolicyKey(policy.policy));
    const normalizedLabel = label.trim();
    const fallbackDescription = normalizedLabel
      ? `Allows users to ${normalizedLabel.charAt(0).toLowerCase()}${normalizedLabel.slice(1)}.`
      : "Allows users to manage theme access.";
    const description =
      override?.description ??
      (trimmedDescription !== "" && trimmedDescription !== policy.policy ? trimmedDescription : fallbackDescription);
    return { label: normalizedLabel || "Theme access", description };
  }

  return {
    label: policy.label ?? policy.policy,
    description: policy.description ?? policy.policy,
  };
}

function humanizeGroupTitle(raw: string): string {
  const normalized = raw.replace(/[._-]+/g, " ").trim();
  if (normalized === "") return "Other";
  const parts = normalized.split(" ").filter((part) => part.trim() !== "");
  if (parts.length === 0) return "Other";
  return parts
    .map((part) => {
      const lower = part.toLowerCase();
      if (POLICY_GROUP_ACRONYMS.has(lower)) {
        return lower.toUpperCase();
      }
      return lower.charAt(0).toUpperCase() + lower.slice(1);
    })
    .join(" ");
}

function resolvePolicyGroup(policyKey: string): PolicyGroupMeta {
  const normalized = policyKey.trim().toLowerCase();
  if (normalized === "") {
    return { key: "other", title: "Other" };
  }

  const segments = normalized.split(".");
  const maxDepth = Math.min(3, segments.length);

  for (let depth = maxDepth; depth >= 1; depth -= 1) {
    const candidate = segments.slice(0, depth).join(".");
    const config = POLICY_GROUP_CONFIG[candidate];
    if (config) {
      return {
        key: config.key ?? candidate,
        title: config.title,
      };
    }
  }

  const fallbackSegments = segments.slice(0, Math.min(2, segments.length));
  const fallbackKey = fallbackSegments.join(".") || "other";
  const fallbackTitle = humanizeGroupTitle(fallbackSegments.join(" "));

  return {
    key: fallbackKey,
    title: fallbackTitle || "Other",
  };
}

const ROLE_NAME_VALIDATION_MESSAGE =
  "Name must be between 2 and 64 characters. Name may only use alphanumeric, spaces, and hyphen characters.";

export default function Roles(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [roles, setRoles] = useState<RoleOption[]>([]);
  const [note, setNote] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [createModalOpen, setCreateModalOpen] = useState<boolean>(false);
  const [name, setName] = useState<string>("");
  const [createError, setCreateError] = useState<string | null>(null);
  const [createShake, setCreateShake] = useState<boolean>(false);
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [renameTarget, setRenameTarget] = useState<RoleOption | null>(null);
  const [renameValue, setRenameValue] = useState<string>("");
  const [renameError, setRenameError] = useState<string | null>(null);
  const [renameShake, setRenameShake] = useState<boolean>(false);
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
  const createShakeTimer = useRef<number | null>(null);
  const renameShakeTimer = useRef<number | null>(null);

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

  useEffect(() => {
    return () => {
      if (createShakeTimer.current !== null) {
        window.clearTimeout(createShakeTimer.current);
        createShakeTimer.current = null;
      }
      if (renameShakeTimer.current !== null) {
        window.clearTimeout(renameShakeTimer.current);
        renameShakeTimer.current = null;
      }
    };
  }, []);

  const resetCreateShake = () => {
    if (createShakeTimer.current !== null) {
      window.clearTimeout(createShakeTimer.current);
      createShakeTimer.current = null;
    }
    setCreateShake(false);
  };

  const triggerCreateShake = () => {
    if (createShakeTimer.current !== null) {
      window.clearTimeout(createShakeTimer.current);
      createShakeTimer.current = null;
    }
    setCreateShake(false);
    const startTimer = window.setTimeout(() => {
      setCreateShake(true);
      createShakeTimer.current = null;
    }, 0);
    createShakeTimer.current = startTimer;
  };

  const isRoleNameValid = (value: string) => {
    if (value.length < 2 || value.length > 64) return false;
    return /^[A-Za-z0-9 -]+$/.test(value);
  };

  const resetRenameShake = () => {
    if (renameShakeTimer.current !== null) {
      window.clearTimeout(renameShakeTimer.current);
      renameShakeTimer.current = null;
    }
    setRenameShake(false);
  };

  const triggerRenameShake = () => {
    if (renameShakeTimer.current !== null) {
      window.clearTimeout(renameShakeTimer.current);
      renameShakeTimer.current = null;
    }
    setRenameShake(false);
    const startTimer = window.setTimeout(() => {
      setRenameShake(true);
      renameShakeTimer.current = null;
    }, 0);
    renameShakeTimer.current = startTimer;
  };

  const openCreateModal = () => {
    if (submitting) return;
    setName("");
    setCreateError(null);
    resetCreateShake();
    setMsg(null);
    setCreateModalOpen(true);
  };

  const closeCreateModal = () => {
    if (submitting) return;
    resetCreateShake();
    setCreateModalOpen(false);
    setName("");
    setCreateError(null);
  };

  const handleConfirmCreate = async () => {
    if (submitting) return;
    const trimmed = name.trim();
    if (!isRoleNameValid(trimmed)) {
      setCreateError(ROLE_NAME_VALIDATION_MESSAGE);
      triggerCreateShake();
      return;
    }

    setSubmitting(true);
    setCreateError(null);
    setMsg(null);
    try {
      const result: CreateRoleResult = await createRole(trimmed);
      if (result.kind === "created") {
        const id = canonicalRoleId(result.roleName);
        const label = roleLabelFromId(id || result.roleName) || result.roleName;
        setMsg(`Created role ${label} (${result.roleId}).`);
        setName("");
        resetCreateShake();
        setCreateModalOpen(false);
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
        resetCreateShake();
        setCreateModalOpen(false);
      } else if (result.kind === "error") {
        if (result.code === "FORBIDDEN") {
          setCreateError("Forbidden. Admin required.");
          setMsg("Forbidden. Admin required.");
        } else if (result.code === "VALIDATION_FAILED") {
          const message = result.message ?? "Validation error.";
          setCreateError(message);
          setMsg(message);
        } else if (result.message) {
          setCreateError(result.message);
          setMsg(result.message);
        } else if (result.code === "NETWORK_ERROR") {
          setCreateError("Network error. Please retry.");
          setMsg("Network error. Please retry.");
        } else {
          setCreateError("Request failed.");
          setMsg("Request failed.");
        }
      }
    } catch {
      setCreateError("Create role request failed.");
      setMsg("Create role request failed.");
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

  type PolicyGroup = {
    key: string;
    title: string;
    policies: PolicyAssignment[];
  };

  const groupedPolicies = useMemo<PolicyGroup[]>(() => {
    if (sortedPolicies.length === 0) return [];

    const map = new Map<string, PolicyGroup>();
    for (const policy of sortedPolicies) {
      const meta = resolvePolicyGroup(policy.policy);
      const existing = map.get(meta.key);
      if (existing) {
        existing.policies.push(policy);
      } else {
        map.set(meta.key, {
          key: meta.key,
          title: meta.title,
          policies: [policy],
        });
      }
    }

    return [...map.values()].sort((a, b) => {
      return a.title.localeCompare(b.title, undefined, { sensitivity: "base" });
    });
  }, [sortedPolicies]);

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

  const createRoleButton = (
    <button
      type="button"
      className="btn btn-primary btn-sm"
      onClick={openCreateModal}
      disabled={submitting}
    >
      Create role
    </button>
  );

  const createInputId = useId();
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
    resetRenameShake();
    setMsg(null);
  };

  const closeRenameModal = () => {
    if (submitting) return;
    setRenameTarget(null);
    setRenameValue("");
    setRenameError(null);
    resetRenameShake();
  };

  const handleConfirmRename = async () => {
    if (!renameTarget) return;
    const trimmed = renameValue.trim();
    if (!isRoleNameValid(trimmed)) {
      setRenameError(ROLE_NAME_VALIDATION_MESSAGE);
      triggerRenameShake();
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
        <div className="d-flex flex-column gap-3">
          <p className="text-muted mb-0">No roles defined.</p>
          <div className="d-flex justify-content-end">{createRoleButton}</div>
        </div>
      ) : (
        <div className="table-responsive">
          <table className="table table-sm align-middle">
            <thead>
              <tr>
                <th scope="col">Role</th>
                <th scope="col" style={{ width: "10rem" }}>Policies</th>
              </tr>
            </thead>
            <tbody>
              {roles.map((role) => {
                const count = policyCountByRole.get(role.id) ?? 0;
                const isActive = selectedRole?.id === role.id;
                return (
                  <tr key={role.id} className={isActive ? "table-active" : undefined}>
                    <td>
                      <button
                        type="button"
                        className={`w-100 text-start border-0 bg-transparent p-0 role-name-trigger ${
                          isActive ? "fw-semibold" : ""
                        }`}
                        onClick={() => (isActive ? closePermissions() : openPermissions(role))}
                        aria-pressed={isActive}
                        aria-label={`${isActive ? "Close" : "Open"} permissions for ${role.name}`}
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
                  </tr>
                );
              })}
            </tbody>
          </table>
          <div className="d-flex justify-content-end mt-3">{createRoleButton}</div>
        </div>
      )}

      {roles.length > 0 && (
        <section className="mt-4" aria-live="polite">
          {selectedRole ? (
            <div className="card">
              <div className="card-header">
                <div className="d-flex flex-wrap align-items-start justify-content-between gap-3">
                  <div className="d-flex align-items-center gap-2 flex-wrap">
                    <h2 className="h5 mb-0">Permissions for {selectedRole.name}</h2>
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center"
                      onClick={() => openRenameModal(selectedRole)}
                      disabled={submitting}
                      title={`Rename ${selectedRole.name}`}
                      aria-label={`Rename ${selectedRole.name}`}
                    >
                      <i className="bi bi-pencil-square" aria-hidden="true"></i>
                      <span className="visually-hidden">Rename</span>
                    </button>
                  </div>
                  <div className="d-flex flex-column align-items-end gap-1">
                    <div className="d-flex align-items-center gap-2">
                      <button
                        type="button"
                        className="btn btn-outline-danger btn-sm d-flex align-items-center justify-content-center"
                        onClick={() => openDeleteModal(selectedRole)}
                        disabled={submitting}
                        title={`Delete ${selectedRole.name}`}
                        aria-label={`Delete ${selectedRole.name}`}
                      >
                        <i className="bi bi-trash" aria-hidden="true"></i>
                        <span className="visually-hidden">Delete</span>
                      </button>
                      <button
                        type="button"
                        className="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center"
                        onClick={closePermissions}
                        disabled={rolePoliciesLoading}
                        title="Close permissions panel"
                        aria-label="Close permissions panel"
                      >
                        <i className="bi bi-x" aria-hidden="true"></i>
                        <span className="visually-hidden">Close</span>
                      </button>
                      <button
                        type="button"
                        className="btn btn-primary btn-sm d-flex align-items-center justify-content-center"
                        onClick={handleSavePolicies}
                        disabled={rolePoliciesLoading || !canEditPolicies || !hasChanges}
                        title="Save permissions"
                        aria-label="Save permissions"
                      >
                        <i className="bi bi-floppy" aria-hidden="true"></i>
                        <span className="visually-hidden">Save</span>
                      </button>
                    </div>
                    <div className="small text-muted text-end">
                      {canEditPolicies
                        ? "Changes take effect immediately after saving."
                        : "Persistence is disabled; assignments are read-only."}
                    </div>
                  </div>
                </div>
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
                  <div className="role-permission-groups">
                    {groupedPolicies.map((group) => (
                      <section
                        key={group.key}
                        aria-labelledby={`policy-group-${group.key}`}
                        className="d-flex flex-column gap-3"
                      >
                        <h3
                          id={`policy-group-${group.key}`}
                          className="h6 text-uppercase text-muted mb-0"
                        >
                          {group.title}
                        </h3>
                        <div className="d-flex flex-column gap-3">
                          {group.policies.map((policy) => {
                            const checkboxId = `policy-${policy.policy.replace(/[^a-zA-Z0-9_-]+/g, "_")}`;
                            const checked = selectedPolicies.includes(policy.policy);
                            const display = getPolicyDisplay(policy);
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
                                  <span className="fw-semibold">{display.label}</span>
                                  <span className="d-block text-muted small">{display.description}</span>
                                </label>
                              </div>
                            );
                          })}
                        </div>
                      </section>
                    ))}
                  </div>
                )}
              </div>
            </div>
          ) : null}
        </section>
      )}

      <ConfirmModal
        open={createModalOpen}
        title="Create Role"
        confirmLabel={submitting ? "Creating…" : "Create"}
        busy={submitting}
        onConfirm={handleConfirmCreate}
        onCancel={closeCreateModal}
        disableBackdropClose={submitting}
        initialFocus="none"
        hideCancelButton
      >
        <div className="mb-3">
          <label htmlFor={createInputId} className="form-label">
            Role name
          </label>
          <input
            id={createInputId}
            type="text"
            className={`form-control role-modal-input${createError ? " is-invalid" : ""}${
              createShake ? " is-shaking" : ""
            }`}
            value={name}
            onChange={(event) => {
              setName(event.target.value);
              if (createError) setCreateError(null);
              resetCreateShake();
            }}
            maxLength={64}
            minLength={2}
            placeholder="e.g., Compliance Lead"
            autoComplete="off"
            disabled={submitting}
            autoFocus
          />
        </div>
        {createError && (
          <p className="text-danger small mt-1 role-modal-error">{createError}</p>
        )}
      </ConfirmModal>

      <ConfirmModal
        open={renameTarget !== null}
        title={renameTarget ? `Rename ${renameTarget.name}` : "Rename Role"}
        confirmLabel={submitting ? "Renaming…" : "Rename"}
        busy={submitting}
        onConfirm={handleConfirmRename}
        onCancel={closeRenameModal}
        disableBackdropClose={submitting}
        initialFocus="none"
        hideCancelButton
      >
        <div className="mb-3">
          <label htmlFor={renameInputId} className="form-label">
            Role name
          </label>
          <input
            id={renameInputId}
            type="text"
            className={`form-control role-modal-input${renameError ? " is-invalid" : ""}${
              renameShake ? " is-shaking" : ""
            }`}
            value={renameValue}
            onChange={(event) => {
              setRenameValue(event.target.value);
              if (renameError) setRenameError(null);
              resetRenameShake();
            }}
            maxLength={64}
            minLength={2}
            autoComplete="off"
            disabled={submitting}
            autoFocus
          />
        </div>
        {renameError && <p className="text-danger small mt-1 role-modal-error">{renameError}</p>}
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
