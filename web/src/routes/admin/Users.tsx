import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { apiDelete, apiGet, apiPost, apiPut, HttpError } from "../../lib/api";
import { listRoles } from "../../lib/api/rbac";
import { roleIdsFromNames, roleLabelFromId, roleOptionsFromList, type RoleOption } from "../../lib/roles";

type User = {
  id: number;
  name: string;
  email: string;
  roles: string[];
};

type Paged<T> = {
  ok: true;
  data: T[];
  meta: { page: number; per_page: number; total: number; total_pages: number };
};

type UserResponse = { ok: true; user: User };

type Banner = { kind: "success" | "error"; text: string };

type EditFormState = {
  name: string;
  email: string;
  password: string;
  roles: string[];
};

const emptyEditForm: EditFormState = {
  name: "",
  email: "",
  password: "",
  roles: [],
};

function isPagedUsers(value: unknown): value is Paged<User> {
  if (!value || typeof value !== "object") return false;
  const obj = value as Record<string, unknown>;
  const meta = obj.meta as Record<string, unknown> | undefined;
  return obj.ok === true && Array.isArray(obj.data) && !!meta && typeof meta.page === "number" && typeof meta.total_pages === "number";
}

function arraysEqualIgnoreOrder(a: string[], b: string[]): boolean {
  if (a.length !== b.length) return false;
  const sa = [...a].sort();
  const sb = [...b].sort();
  for (let i = 0; i < sa.length; i++) {
    if (sa[i] !== sb[i]) return false;
  }
  return true;
}

function messageFromError(err: unknown, fallback: string): string {
  if (err instanceof HttpError) {
    const body = err.body;
    if (body && typeof body === "object") {
      const maybeMessage = (body as Record<string, unknown>).message;
      if (typeof maybeMessage === "string" && maybeMessage.trim() !== "") {
        return maybeMessage;
      }
    }
    if (typeof err.message === "string" && err.message.trim() !== "") {
      return err.message;
    }
    return fallback;
  }
  if (err instanceof Error && typeof err.message === "string" && err.message.trim() !== "") {
    return err.message;
  }
  return fallback;
}

export default function Users(): JSX.Element {
  const [q, setQ] = useState<string>("");
  const [page, setPage] = useState<number>(1);
  const [perPage] = useState<number>(25);
  const [items, setItems] = useState<User[]>([]);
  const [meta, setMeta] = useState<Paged<User>["meta"]>({ page: 1, per_page: 25, total: 0, total_pages: 0 });
  const [listLoading, setListLoading] = useState<boolean>(false);
  const [banner, setBanner] = useState<Banner | null>(null);

  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([]);
  const [rolesLoading, setRolesLoading] = useState<boolean>(false);
  const [rolesError, setRolesError] = useState<string | null>(null);

  const [createRoles, setCreateRoles] = useState<string[]>([]);
  const [createBusy, setCreateBusy] = useState<boolean>(false);
  const [createError, setCreateError] = useState<string | null>(null);

  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [editForm, setEditForm] = useState<EditFormState>(emptyEditForm);
  const [editBusy, setEditBusy] = useState<boolean>(false);
  const [editError, setEditError] = useState<string | null>(null);

  const [deleteCandidate, setDeleteCandidate] = useState<User | null>(null);
  const [deleteBusy, setDeleteBusy] = useState<boolean>(false);

  const canPrev = meta.page > 1;
  const canNext = meta.page < meta.total_pages;
  const roleListSize = Math.max(4, Math.min(8, roleOptions.length || 4));

  useEffect(() => {
    let active = true;
    setRolesLoading(true);
    setRolesError(null);
    void listRoles()
      .then((res) => {
        if (!active) return;
        if (res.ok) {
          setRoleOptions(roleOptionsFromList(res.roles ?? []));
        } else {
          setRoleOptions([]);
          setRolesError("Failed to load roles.");
        }
      })
      .catch(() => {
        if (!active) return;
        setRoleOptions([]);
        setRolesError("Failed to load roles.");
      })
      .finally(() => {
        if (active) setRolesLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    const valid = new Set(roleOptions.map((option) => option.id));
    setCreateRoles((prev) => prev.filter((role) => valid.has(role)));
    setEditForm((prev) => {
      if (!selectedUser) {
        return prev;
      }
      const filtered = prev.roles.filter((role) => valid.has(role));
      if (filtered.length === prev.roles.length) {
        return prev;
      }
      return { ...prev, roles: filtered };
    });
  }, [roleOptions, selectedUser]);

  useEffect(() => {
    if (!selectedUser) {
      setEditForm({ ...emptyEditForm });
      setEditError(null);
      return;
    }
    setEditForm({
      name: selectedUser.name,
      email: selectedUser.email,
      password: "",
      roles: roleIdsFromNames(selectedUser.roles),
    });
    setEditError(null);
  }, [selectedUser]);

  const selectedUserRoleIds = useMemo(() => (selectedUser ? roleIdsFromNames(selectedUser.roles) : []), [selectedUser]);
  const selectedUserRoleLabels = useMemo(() => {
    if (!selectedUser) return [] as string[];
    const fromIds = selectedUserRoleIds.map((id) => roleLabelFromId(id));
    return fromIds.length > 0 ? fromIds : selectedUser.roles;
  }, [selectedUser, selectedUserRoleIds]);

  const editDirty = useMemo(() => {
    if (!selectedUser) return false;
    const passwordChanged = editForm.password.trim() !== "";
    const rolesChanged = !arraysEqualIgnoreOrder(editForm.roles, selectedUserRoleIds);
    return (
      selectedUser.name !== editForm.name ||
      selectedUser.email !== editForm.email ||
      passwordChanged ||
      rolesChanged
    );
  }, [editForm, selectedUser, selectedUserRoleIds]);

  const load = useCallback(async () => {
    setListLoading(true);
    try {
      const res = await apiGet<unknown>("/api/users", { q, page, per_page: perPage });
      if (isPagedUsers(res)) {
        setItems(res.data);
        setMeta(res.meta);
      } else {
        setItems([]);
        setMeta({ page: 1, per_page: perPage, total: 0, total_pages: 0 });
        setBanner({ kind: "error", text: "Users API shape invalid or not implemented." });
      }
    } catch (err) {
      setItems([]);
      const message = messageFromError(err, "Failed to load users.");
      setBanner({ kind: "error", text: message });
    } finally {
      setListLoading(false);
    }
  }, [page, perPage, q]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleSearchSubmit = useCallback((event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (page !== 1) {
      setPage(1);
    } else {
      void load();
    }
  }, [page, load]);

  async function handleCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (createBusy) return;
    const formEl = event.currentTarget;
    const form = new FormData(formEl);
    const payload = {
      name: String(form.get("name") ?? ""),
      email: String(form.get("email") ?? ""),
      password: String(form.get("password") ?? ""),
      roles: createRoles,
    };

    setCreateBusy(true);
    setCreateError(null);
    setBanner(null);

    try {
      await apiPost<UserResponse, typeof payload>("/api/users", payload);
      formEl.reset();
      setCreateRoles([]);
      setBanner({ kind: "success", text: "User created." });
      if (page !== 1) {
        setPage(1);
      } else {
        await load();
      }
    } catch (err) {
      const message = messageFromError(err, "Create failed.");
      setCreateError(message);
      setBanner({ kind: "error", text: message });
    } finally {
      setCreateBusy(false);
    }
  }

  function openEdit(user: User) {
    setSelectedUser(user);
    setDeleteCandidate(null);
  }

  function resetEditForm() {
    if (!selectedUser) return;
    setEditForm({
      name: selectedUser.name,
      email: selectedUser.email,
      password: "",
      roles: roleIdsFromNames(selectedUser.roles),
    });
    setEditError(null);
  }

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedUser || editBusy) return;

    const payload: {
      name: string;
      email: string;
      password?: string;
      roles: string[];
    } = {
      name: editForm.name.trim(),
      email: editForm.email.trim(),
      roles: editForm.roles,
    };

    const trimmedPassword = editForm.password.trim();
    if (trimmedPassword !== "") {
      payload.password = trimmedPassword;
    }

    setEditBusy(true);
    setEditError(null);
    setBanner(null);

    try {
      const res = await apiPut<UserResponse, typeof payload>(`/api/users/${selectedUser.id}`, payload);
      setSelectedUser(res.user);
      setBanner({ kind: "success", text: "User updated." });
      await load();
    } catch (err) {
      const message = messageFromError(err, "Update failed.");
      setEditError(message);
      setBanner({ kind: "error", text: message });
    } finally {
      setEditBusy(false);
    }
  }

  function beginDelete(user: User) {
    setDeleteCandidate(user);
  }

  async function confirmDelete() {
    if (!deleteCandidate || deleteBusy) return;

    setDeleteBusy(true);
    setBanner(null);

    try {
      await apiDelete<{ ok: true }>(`/api/users/${deleteCandidate.id}`);
      if (selectedUser && selectedUser.id === deleteCandidate.id) {
        setSelectedUser(null);
      }
      setDeleteCandidate(null);
      setBanner({ kind: "success", text: "User deleted." });
      await load();
    } catch (err) {
      const message = messageFromError(err, "Delete failed.");
      setBanner({ kind: "error", text: message });
      setDeleteCandidate(null);
    } finally {
      setDeleteBusy(false);
    }
  }

  const cancelDelete = () => {
    if (deleteBusy) return;
    setDeleteCandidate(null);
  };

  return (
    <main className="container py-4">
      <header className="d-flex align-items-center justify-content-between mb-3">
        <h1 className="h3 mb-0">Users</h1>
      </header>

      <section aria-live="polite" className="mb-3">
        {banner && (
          <div className={`alert ${banner.kind === "success" ? "alert-success" : "alert-danger"}`} role="alert">
            {banner.text}
          </div>
        )}
      </section>

      <section aria-labelledby="users-search" className="mb-4">
        <h2 id="users-search" className="h5">Search</h2>
        <form className="row g-2 align-items-end" onSubmit={handleSearchSubmit}>
          <div className="col-12 col-md-6">
            <label htmlFor="users_q" className="form-label">Query</label>
            <input
              id="users_q"
              name="q"
              value={q}
              onChange={(event) => setQ(event.currentTarget.value)}
              placeholder="Search name, email, or role"
              className="form-control"
              autoComplete="off"
            />
          </div>
          <div className="col-auto">
            <button type="submit" className="btn btn-primary" disabled={listLoading}>
              {listLoading ? "Searching..." : "Search"}
            </button>
          </div>
        </form>
      </section>

      <div className="table-responsive">
        <table className="table table-sm align-middle">
          <thead>
            <tr>
              <th scope="col" style={{ width: "6rem" }}>ID</th>
              <th scope="col">Name</th>
              <th scope="col">Email</th>
              <th scope="col">Roles</th>
              <th scope="col" style={{ width: "9rem" }} className="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            {items.map((user) => (
              <tr key={user.id} className={selectedUser?.id === user.id ? "table-active" : undefined}>
                <td>{user.id}</td>
                <td>{user.name}</td>
                <td>{user.email}</td>
                <td>{user.roles.length > 0 ? user.roles.join(", ") : <span className="text-muted">None</span>}</td>
                <td className="text-end">
                  <div className="btn-group btn-group-sm" role="group" aria-label={`Actions for ${user.email}`}>
                    <button type="button" className="btn btn-outline-primary" onClick={() => openEdit(user)} disabled={editBusy && selectedUser?.id === user.id}>
                      Edit
                    </button>
                    <button
                      type="button"
                      className="btn btn-outline-danger"
                      onClick={() => beginDelete(user)}
                      disabled={deleteBusy && deleteCandidate?.id === user.id}
                    >
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            ))}
            {items.length === 0 && !listLoading && (
              <tr>
                <td colSpan={5} className="text-center text-muted py-4">No users</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <nav aria-label="Pagination" className="d-flex align-items-center gap-3 mt-2">
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => setPage((prev) => Math.max(1, prev - 1))} disabled={!canPrev || listLoading}>
          Prev
        </button>
        <span className="text-muted">Page {meta.page} of {Math.max(1, meta.total_pages)}</span>
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => setPage((prev) => prev + 1)} disabled={!canNext || listLoading}>
          Next
        </button>
      </nav>

      {deleteCandidate && (
        <div className="alert alert-warning d-flex align-items-center justify-content-between mt-4" role="alert">
          <div>
            <strong>Delete {deleteCandidate.email}?</strong>
            <div className="small">This action cannot be undone.</div>
          </div>
          <div className="d-flex gap-2">
            <button type="button" className="btn btn-danger" onClick={confirmDelete} disabled={deleteBusy}>
              {deleteBusy ? "Deleting..." : "Delete"}
            </button>
            <button type="button" className="btn btn-outline-secondary" onClick={cancelDelete} disabled={deleteBusy}>
              Cancel
            </button>
          </div>
        </div>
      )}

      <section className="row g-4 mt-4">
        <div className="col-12 col-lg-6">
          <div className="card h-100">
            <div className="card-header">Edit user</div>
            <div className="card-body">
              {!selectedUser && <p className="text-muted mb-0">Select a user to edit their details.</p>}
              {selectedUser && (
                <form onSubmit={handleEditSubmit} className="row g-3" noValidate>
                  <div className="col-12">
                    <div className="fw-semibold">User ID</div>
                    <div>{selectedUser.id}</div>
                  </div>
                  <div className="col-12">
                    <div className="fw-semibold">Current roles</div>
                    <div>{selectedUserRoleLabels.length > 0 ? selectedUserRoleLabels.join(", ") : <span className="text-muted">None</span>}</div>
                  </div>

                  <div className="col-12">
                    <label htmlFor="edit_name" className="form-label">Name</label>
                    <input
                      id="edit_name"
                      name="name"
                      className="form-control"
                      value={editForm.name}
                      onChange={(event) => {
                        const value = event.currentTarget.value;
                        setEditForm((prev) => ({ ...prev, name: value }));
                      }}
                      required
                    />
                  </div>

                  <div className="col-12">
                    <label htmlFor="edit_email" className="form-label">Email</label>
                    <input
                      id="edit_email"
                      name="email"
                      className="form-control"
                      type="email"
                      value={editForm.email}
                      onChange={(event) => {
                        const value = event.currentTarget.value;
                        setEditForm((prev) => ({ ...prev, email: value }));
                      }}
                      required
                    />
                  </div>

                  <div className="col-12">
                    <label htmlFor="edit_password" className="form-label">Reset password</label>
                    <input
                      id="edit_password"
                      name="password"
                      className="form-control"
                      type="password"
                      placeholder="Leave blank to keep current password"
                      value={editForm.password}
                      onChange={(event) => {
                        const value = event.currentTarget.value;
                        setEditForm((prev) => ({ ...prev, password: value }));
                      }}
                    />
                  </div>

                  <div className="col-12">
                    <label htmlFor="edit_roles" className="form-label">Roles</label>
                    <select
                      id="edit_roles"
                      name="roles"
                      multiple
                      className="form-select"
                      size={roleListSize}
                      value={editForm.roles}
                      onChange={(event) => {
                        const values = Array.from(event.currentTarget.selectedOptions).map((option) => option.value);
                        setEditForm((prev) => ({ ...prev, roles: values }));
                      }}
                      aria-describedby="edit_roles_help"
                    >
                      {roleOptions.map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.name}
                        </option>
                      ))}
                    </select>
                    <div id="edit_roles_help" className="form-text">
                      {rolesLoading ? "Loading roles..." : rolesError ?? (roleOptions.length === 0 ? "No roles available." : "Select one or more roles. Use Ctrl/Cmd-click to toggle selections.")}
                    </div>
                  </div>

                  {editError && (
                    <div className="col-12">
                      <div className="alert alert-danger py-2 mb-0" role="alert">{editError}</div>
                    </div>
                  )}

                  <div className="col-12 d-flex gap-2">
                    <button type="submit" className="btn btn-primary" disabled={!editDirty || editBusy}>
                      {editBusy ? "Saving..." : "Save"}
                    </button>
                    <button type="button" className="btn btn-outline-secondary" onClick={resetEditForm} disabled={editBusy || !editDirty}>
                      Reset
                    </button>
                  </div>
                </form>
              )}
            </div>
          </div>
        </div>

        <div className="col-12 col-lg-6">
          <div className="card h-100">
            <div className="card-header">Create user</div>
            <div className="card-body">
              {createError && (
                <div className="alert alert-danger py-2" role="alert">{createError}</div>
              )}
              <form onSubmit={handleCreate} className="row g-3" noValidate>
                <div className="col-12">
                  <label htmlFor="create_name" className="form-label">Name</label>
                  <input id="create_name" name="name" className="form-control" required autoComplete="off" />
                </div>
                <div className="col-12">
                  <label htmlFor="create_email" className="form-label">Email</label>
                  <input id="create_email" name="email" type="email" className="form-control" required autoComplete="off" />
                </div>
                <div className="col-12">
                  <label htmlFor="create_password" className="form-label">Password</label>
                  <input id="create_password" name="password" type="password" className="form-control" required autoComplete="new-password" />
                </div>
                <div className="col-12">
                  <label htmlFor="create_roles" className="form-label">Roles</label>
                  <select
                    id="create_roles"
                    name="roles"
                    multiple
                    className="form-select"
                    size={roleListSize}
                    value={createRoles}
                    onChange={(event) => {
                      const values = Array.from(event.currentTarget.selectedOptions).map((option) => option.value);
                      setCreateRoles(values);
                    }}
                    aria-describedby="create_roles_help"
                  >
                    {roleOptions.map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.name}
                      </option>
                    ))}
                  </select>
                  <div id="create_roles_help" className="form-text">
                    {rolesLoading ? "Loading roles..." : rolesError ?? (roleOptions.length === 0 ? "No roles available." : "Select one or more roles. Use Ctrl/Cmd-click to toggle selections.")}
                  </div>
                </div>
                <div className="col-12">
                  <button type="submit" className="btn btn-success" disabled={createBusy}>
                    {createBusy ? "Creating..." : "Create"}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </section>
    </main>
  );
}
