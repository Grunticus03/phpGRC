import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { apiDelete, apiGet, apiPost, apiPut, HttpError } from "../../lib/api";
import { listRoles } from "../../lib/api/rbac";
import { roleIdsFromNames, roleLabelFromId, roleOptionsFromList, type RoleOption } from "../../lib/roles";
import { useToast } from "../../components/toast/ToastProvider";

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

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/i;

function extractFieldErrors(body: unknown): Record<string, string> {
  if (!body || typeof body !== "object") return {};
  const errors = (body as { errors?: unknown }).errors;
  if (!errors || typeof errors !== "object") return {};
  const out: Record<string, string> = {};
  Object.entries(errors as Record<string, unknown>).forEach(([key, value]) => {
    if (Array.isArray(value)) {
      const first = value.find((item) => typeof item === "string" && item.trim() !== "");
      if (typeof first === "string") {
        out[key] = first;
      }
    } else if (typeof value === "string" && value.trim() !== "") {
      out[key] = value;
    }
  });
  return out;
}

function messageFromError(err: unknown, fallback: string): string {
  if (err instanceof HttpError) {
    const body = err.body;
    if (body && typeof body === "object") {
      const maybeMessage = (body as Record<string, unknown>).message;
      if (typeof maybeMessage === "string" && maybeMessage.trim() !== "") {
        return maybeMessage;
      }
      const fieldErrors = extractFieldErrors(body);
      const firstFieldError = Object.values(fieldErrors).find(
        (value) => typeof value === "string" && value.trim() !== ""
      );
      if (typeof firstFieldError === "string") {
        return firstFieldError;
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
  const [appliedQ, setAppliedQ] = useState<string>("");
  const [page, setPage] = useState<number>(1);
  const [perPage] = useState<number>(25);
  const [items, setItems] = useState<User[]>([]);
  const [meta, setMeta] = useState<Paged<User>["meta"]>({ page: 1, per_page: 25, total: 0, total_pages: 0 });
  const [listLoading, setListLoading] = useState<boolean>(false);
  const toast = useToast();
  const { success: showSuccess, danger: showDanger } = toast;

  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([]);
  const [rolesLoading, setRolesLoading] = useState<boolean>(false);
  const [rolesError, setRolesError] = useState<string | null>(null);

  const [createRoles, setCreateRoles] = useState<string[]>([]);
  const [createBusy, setCreateBusy] = useState<boolean>(false);
  const [createError, setCreateError] = useState<string | null>(null);
  const [createFieldErrors, setCreateFieldErrors] = useState<Record<string, string>>({});

  const [selectedUser, setSelectedUser] = useState<User | null>(null);
  const [editForm, setEditForm] = useState<EditFormState>(emptyEditForm);
  const [editBusy, setEditBusy] = useState<boolean>(false);
  const [editError, setEditError] = useState<string | null>(null);
  const [editFieldErrors, setEditFieldErrors] = useState<Record<string, string>>({});

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
      setEditFieldErrors({});
      return;
    }
    setEditForm({
      name: selectedUser.name,
      email: selectedUser.email,
      password: "",
      roles: roleIdsFromNames(selectedUser.roles),
    });
    setEditError(null);
    setEditFieldErrors({});
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
      const params = {
        q: appliedQ.trim() === "" ? undefined : appliedQ,
        page,
        per_page: perPage,
      };
      const res = await apiGet<unknown>("/api/users", params);
      if (isPagedUsers(res)) {
        setItems(res.data);
        setMeta(res.meta);
      } else {
        setItems([]);
        setMeta({ page: 1, per_page: perPage, total: 0, total_pages: 0 });
        showDanger("Users API shape invalid or not implemented.");
      }
    } catch (err) {
      setItems([]);
      const message = messageFromError(err, "Failed to load users.");
      showDanger(message);
    } finally {
      setListLoading(false);
    }
  }, [appliedQ, page, perPage, showDanger]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleSearchSubmit = useCallback(
    (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      const trimmed = q.trim();
      if (trimmed === appliedQ) {
        if (page !== 1) {
          setPage(1);
        } else {
          void load();
        }
        return;
      }
      setAppliedQ(trimmed);
      if (page !== 1) {
        setPage(1);
      }
    },
    [appliedQ, load, page, q]
  );

  async function handleCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (createBusy) return;
    const formEl = event.currentTarget;
    const form = new FormData(formEl);
    const name = String(form.get("name") ?? "").trim();
    const email = String(form.get("email") ?? "").trim();
    const rawPassword = String(form.get("password") ?? "");
    const password = rawPassword.trim();

    const nextFieldErrors: Record<string, string> = {};
    if (name.length < 2) {
      nextFieldErrors.name = "Name is required.";
    }
    if (email === "") {
      nextFieldErrors.email = "Email is required.";
    } else if (!EMAIL_REGEX.test(email)) {
      nextFieldErrors.email = "Enter a valid email address.";
    }
    if (password === "") {
      nextFieldErrors.password = "Password is required.";
    } else if (password.length < 8) {
      nextFieldErrors.password = "Password must be at least 8 characters.";
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setCreateFieldErrors(nextFieldErrors);
      return;
    }

    const payload = {
      name,
      email,
      password,
      roles: createRoles,
    };

    setCreateFieldErrors({});
    setCreateBusy(true);
    setCreateError(null);

    try {
      await apiPost<UserResponse, typeof payload>("/api/users", payload);
      formEl.reset();
      setCreateRoles([]);
      setCreateFieldErrors({});
      showSuccess("User created.");
      if (page !== 1) {
        setPage(1);
      } else {
        await load();
      }
    } catch (err) {
      const message = messageFromError(err, "Create failed.");
      setCreateError(message);
      showDanger(message);
      if (err instanceof HttpError && err.body) {
        const fieldErrors = extractFieldErrors(err.body);
        if (Object.keys(fieldErrors).length > 0) {
          setCreateFieldErrors(fieldErrors);
        }
      }
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
    setEditFieldErrors({});
  }

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedUser || editBusy) return;

    const trimmedName = editForm.name.trim();
    const trimmedEmail = editForm.email.trim();
    const trimmedPassword = editForm.password.trim();

    const nextFieldErrors: Record<string, string> = {};
    if (trimmedName.length < 2) {
      nextFieldErrors.name = "Name is required.";
    }
    if (trimmedEmail === "") {
      nextFieldErrors.email = "Email is required.";
    } else if (!EMAIL_REGEX.test(trimmedEmail)) {
      nextFieldErrors.email = "Enter a valid email address.";
    }
    if (trimmedPassword !== "" && trimmedPassword.length < 8) {
      nextFieldErrors.password = "Password must be at least 8 characters.";
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setEditFieldErrors(nextFieldErrors);
      setEditError("Please fix the highlighted fields.");
      return;
    }

    const payload: {
      name: string;
      email: string;
      password?: string;
      roles: string[];
    } = {
      name: trimmedName,
      email: trimmedEmail,
      roles: editForm.roles,
    };

    if (trimmedPassword !== "") {
      payload.password = trimmedPassword;
    }

    setEditBusy(true);
    setEditError(null);
    setEditFieldErrors({});

    try {
      const res = await apiPut<UserResponse, typeof payload>(`/api/users/${selectedUser.id}`, payload);
      setSelectedUser(res.user);
      showSuccess("User updated.");
      await load();
    } catch (err) {
      const message = messageFromError(err, "Update failed.");
      setEditError(message);
      showDanger(message);
      if (err instanceof HttpError && err.body) {
        const fieldErrors = extractFieldErrors(err.body);
        if (Object.keys(fieldErrors).length > 0) {
          setEditFieldErrors(fieldErrors);
        }
      }
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

    try {
      await apiDelete<{ ok: true }>(`/api/users/${deleteCandidate.id}`);
      if (selectedUser && selectedUser.id === deleteCandidate.id) {
        setSelectedUser(null);
      }
      setDeleteCandidate(null);
      showSuccess("User deleted.");
      await load();
    } catch (err) {
      const message = messageFromError(err, "Delete failed.");
      showDanger(message);
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
              {selectedUser ? (
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
                    <label htmlFor="edit_name" className="form-label">Name <span className="text-danger" aria-hidden="true">*</span></label>
                    <input
                      id="edit_name"
                      name="name"
                      className={`form-control${editFieldErrors.name ? " is-invalid" : ""}`}
                      value={editForm.name}
                      onChange={(event) => {
                        const value = event.currentTarget.value;
                        setEditForm((prev) => ({ ...prev, name: value }));
                      }}
                      required
                      aria-invalid={!!editFieldErrors.name}
                      aria-describedby={editFieldErrors.name ? "edit_name_error" : undefined}
                    />
                    {editFieldErrors.name ? (
                      <div id="edit_name_error" className="invalid-feedback d-block">{editFieldErrors.name}</div>
                    ) : null}
                  </div>

                  <div className="col-12">
                    <label htmlFor="edit_email" className="form-label">Email <span className="text-danger" aria-hidden="true">*</span></label>
                    <input
                      id="edit_email"
                      name="email"
                      className={`form-control${editFieldErrors.email ? " is-invalid" : ""}`}
                      type="email"
                      value={editForm.email}
                      onChange={(event) => {
                        const value = event.currentTarget.value;
                        setEditForm((prev) => ({ ...prev, email: value }));
                      }}
                      required
                      aria-invalid={!!editFieldErrors.email}
                      aria-describedby={editFieldErrors.email ? "edit_email_error" : undefined}
                    />
                    {editFieldErrors.email ? (
                      <div id="edit_email_error" className="invalid-feedback d-block">{editFieldErrors.email}</div>
                    ) : null}
                  </div>

                  <div className="col-12">
                    <label htmlFor="edit_password" className="form-label">Reset password</label>
                    <input
                      id="edit_password"
                      name="password"
                      className={`form-control${editFieldErrors.password ? " is-invalid" : ""}`}
                      type="password"
                      placeholder="Leave blank to keep current password"
                      value={editForm.password}
                      onChange={(event) => {
                        const value = event.currentTarget.value;
                        setEditForm((prev) => ({ ...prev, password: value }));
                      }}
                      aria-invalid={!!editFieldErrors.password}
                      aria-describedby={editFieldErrors.password ? "edit_password_error" : undefined}
                    />
                    {editFieldErrors.password ? (
                      <div id="edit_password_error" className="invalid-feedback d-block">{editFieldErrors.password}</div>
                    ) : null}
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
                      {rolesLoading ? "Loading roles..." : rolesError ?? (roleOptions.length === 0 ? "No roles available." : "Use Ctrl/Cmd-click to toggle selections.")}
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
              ) : (
                <p className="text-muted mb-0">No user selected.</p>
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
                  <label htmlFor="create_name" className="form-label">Name <span className="text-danger" aria-hidden="true">*</span></label>
                  <input
                    id="create_name"
                    name="name"
                    className={`form-control${createFieldErrors.name ? " is-invalid" : ""}`}
                    required
                    autoComplete="off"
                    aria-invalid={!!createFieldErrors.name}
                    aria-describedby={createFieldErrors.name ? "create_name_error" : undefined}
                  />
                  {createFieldErrors.name ? (
                    <div id="create_name_error" className="invalid-feedback d-block">{createFieldErrors.name}</div>
                  ) : null}
                </div>
                <div className="col-12">
                  <label htmlFor="create_email" className="form-label">Email <span className="text-danger" aria-hidden="true">*</span></label>
                  <input
                    id="create_email"
                    name="email"
                    type="email"
                    className={`form-control${createFieldErrors.email ? " is-invalid" : ""}`}
                    required
                    autoComplete="off"
                    aria-invalid={!!createFieldErrors.email}
                    aria-describedby={createFieldErrors.email ? "create_email_error" : undefined}
                  />
                  {createFieldErrors.email ? (
                    <div id="create_email_error" className="invalid-feedback d-block">{createFieldErrors.email}</div>
                  ) : null}
                </div>
                <div className="col-12">
                  <label htmlFor="create_password" className="form-label">Password <span className="text-danger" aria-hidden="true">*</span></label>
                  <input
                    id="create_password"
                    name="password"
                    type="password"
                    className={`form-control${createFieldErrors.password ? " is-invalid" : ""}`}
                    required
                    autoComplete="new-password"
                    aria-invalid={!!createFieldErrors.password}
                    aria-describedby={createFieldErrors.password ? "create_password_error" : undefined}
                  />
                  {createFieldErrors.password ? (
                    <div id="create_password_error" className="invalid-feedback d-block">{createFieldErrors.password}</div>
                  ) : null}
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
                    {rolesLoading ? "Loading roles..." : rolesError ?? (roleOptions.length === 0 ? "No roles available." : "Use Ctrl/Cmd-click to toggle selections.")}
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
