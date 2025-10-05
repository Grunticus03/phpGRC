import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  listRoles,
  getUserRoles,
  attachUserRole,
  detachUserRole,
  replaceUserRoles,
  searchUsers,
  type UserRolesResponse,
  type UserRolesResponseOk,
  type RoleListResponse,
  type UserSummary,
  type UserSearchOk,
} from "../../lib/api/rbac";

type User = { id: number; name: string; email: string };

export default function UserRoles(): JSX.Element {
  // Role catalog
  const [allRoles, setAllRoles] = useState<string[]>([]);
  const [loadingRoles, setLoadingRoles] = useState<boolean>(false);

  // Direct lookup by ID
  const [userIdInput, setUserIdInput] = useState<string>("");
  const [loadingUser, setLoadingUser] = useState<boolean>(false);
  const [user, setUser] = useState<User | null>(null);
  const [userRoles, setUserRoles] = useState<string[]>([]);

  // Attach/replace state
  const [attachChoice, setAttachChoice] = useState<string>("");
  const [replaceSelection, setReplaceSelection] = useState<string[]>([]);
  const [replaceBusy, setReplaceBusy] = useState<boolean>(false);

  // Search state
  const [q, setQ] = useState<string>("");
  const [page, setPage] = useState<number>(1);
  const [perPage, setPerPage] = useState<number>(50);
  const [searching, setSearching] = useState<boolean>(false);
  const [results, setResults] = useState<UserSummary[]>([]);
  const [meta, setMeta] = useState<{ page: number; per_page: number; total: number; total_pages: number } | null>(null);

  // Messages
  const [msg, setMsg] = useState<string | null>(null);
  const attachBtnRef = useRef<HTMLButtonElement | null>(null);

  // Load role catalog
  useEffect(() => {
    const ctl = new AbortController();
    (async () => {
      setLoadingRoles(true);
      try {
        const res: RoleListResponse = await listRoles();
        if (!ctl.signal.aborted && res.ok) {
          setAllRoles(res.roles ?? []);
        }
      } catch {
        setMsg("Failed to load roles.");
      } finally {
        if (!ctl.signal.aborted) setLoadingRoles(false);
      }
    })();
    return () => ctl.abort();
  }, []);

  async function loadUser() {
    setMsg(null);
    setUser(null);
    setUserRoles([]);
    setReplaceSelection([]);
    const idNum = Number(userIdInput);
    if (!Number.isInteger(idNum) || idNum <= 0) {
      setMsg("Enter a valid User ID.");
      return;
    }
    setLoadingUser(true);
    try {
      const res: UserRolesResponse = await getUserRoles(idNum);
      if (res.ok) {
        const ok = res as UserRolesResponseOk;
        setUser(ok.user);
        const roles = ok.roles ?? [];
        setUserRoles(roles);
        setReplaceSelection(roles);
        setAttachChoice("");
        setMsg(null);
        queueMicrotask(() => attachBtnRef.current?.focus());
      } else {
        setMsg(`Error: ${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setMsg("Network error.");
    } finally {
      setLoadingUser(false);
    }
  }

  const attachable = useMemo(
    () => allRoles.filter((r) => !userRoles.includes(r)),
    [allRoles, userRoles]
  );

  async function attachRole() {
    if (!user) return;
    if (!attachChoice) return;
    setMsg(null);
    try {
      const res = await attachUserRole(user.id, attachChoice);
      if (res.ok) {
        const ok = res as UserRolesResponseOk;
        const roles = ok.roles ?? [];
        setUserRoles(roles);
        setReplaceSelection(roles);
        setAttachChoice("");
        setMsg("Role attached.");
      } else {
        setMsg(`Attach failed: ${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setMsg("Attach failed.");
    }
  }

  async function detachRole(role: string) {
    if (!user) return;
    setMsg(null);
    try {
      const res = await detachUserRole(user.id, role);
      if (res.ok) {
        const ok = res as UserRolesResponseOk;
        const roles = ok.roles ?? [];
        setUserRoles(roles);
        setReplaceSelection(roles);
        setMsg("Role detached.");
      } else {
        setMsg(`Detach failed: ${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setMsg("Detach failed.");
    }
  }

  function onReplaceSelectChange(e: React.ChangeEvent<HTMLSelectElement>) {
    const opts = Array.from(e.currentTarget.options);
    const selected = opts.filter((o) => o.selected).map((o) => o.value);
    setReplaceSelection(selected);
  }

  function arraysEqualUnordered(a: string[], b: string[]): boolean {
    if (a.length !== b.length) return false;
    const sa = [...a].sort();
    const sb = [...b].sort();
    for (let i = 0; i < sa.length; i++) if (sa[i] !== sb[i]) return false;
    return true;
  }

  async function doReplace() {
    if (!user) return;
    setMsg(null);
    setReplaceBusy(true);
    try {
      const res = await replaceUserRoles(user.id, replaceSelection);
      if (res.ok) {
        const ok = res as UserRolesResponseOk;
        const roles = ok.roles ?? [];
        setUserRoles(roles);
        setReplaceSelection(roles);
        setMsg("Roles replaced.");
      } else {
        setMsg(`Replace failed: ${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setMsg("Replace failed.");
    } finally {
      setReplaceBusy(false);
    }
  }

  async function runSearch(targetPage?: number) {
    setSearching(true);
    setMsg(null);
    try {
      const p = typeof targetPage === "number" ? targetPage : page;
      const res = await searchUsers(q, p, perPage);
      if (res.ok) {
        const ok = res as UserSearchOk;
        setResults(ok.data);
        setMeta(ok.meta);
        setPage(ok.meta.page);
      } else {
        setResults([]);
        setMeta(null);
        setMsg(`Search failed: ${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setResults([]);
      setMeta(null);
      setMsg("Search failed.");
    } finally {
      setSearching(false);
    }
  }

  function selectUser(u: UserSummary) {
    setUserIdInput(String(u.id));
    // clear search results to avoid duplicate name text in DOM
    setResults([]);
    setMeta(null);
    // chain into role load
    void loadUserWith(u.id);
  }

  async function loadUserWith(id: number) {
    setMsg(null);
    setUser(null);
    setUserRoles([]);
    setReplaceSelection([]);
    setLoadingUser(true);
    try {
      const res: UserRolesResponse = await getUserRoles(id);
      if (res.ok) {
        const ok = res as UserRolesResponseOk;
        setUser(ok.user);
        const roles = ok.roles ?? [];
        setUserRoles(roles);
        setReplaceSelection(roles);
        setAttachChoice("");
        queueMicrotask(() => attachBtnRef.current?.focus());
      } else {
        setMsg(`Error: ${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setMsg("Network error.");
    } finally {
      setLoadingUser(false);
    }
  }

  return (
    <main className="container py-3">
      <h1 className="mb-3">User Roles</h1>

      <section aria-labelledby="search">
        <h2 id="search" className="h5">Search users</h2>
        <div className="row g-2 align-items-end">
          <div className="col-12 col-md-5">
            <label htmlFor="q" className="form-label">Query</label>
            <input
              id="q"
              type="text"
              className="form-control"
              value={q}
              onChange={(e) => setQ(e.currentTarget.value)}
              placeholder="name or email"
            />
          </div>
          <div className="col-6 col-md-2">
            <label htmlFor="per_page" className="form-label">Per page</label>
            <input
              id="per_page"
              type="number"
              inputMode="numeric"
              className="form-control"
              value={perPage}
              min={1}
              max={500}
              onChange={(e) => {
                const v = Number(e.currentTarget.value) || 50;
                setPerPage(Math.max(1, Math.min(500, v)));
              }}
            />
          </div>
          <div className="col-auto">
            <button type="button" className="btn btn-secondary" onClick={() => { setPage(1); void runSearch(1); }} disabled={searching}>
              {searching ? "Searching…" : "Search"}
            </button>
          </div>
        </div>

        <div className="mt-3">
          {results.length === 0 && !searching && <div className="text-muted">No matches</div>}
          {results.length > 0 && (
            <div className="table-responsive">
              <table className="table table-sm align-middle">
                <thead>
                  <tr>
                    <th style={{ width: "6rem" }}>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th style={{ width: "8rem" }}>Action</th>
                  </tr>
                </thead>
                <tbody>
                  {results.map((u) => (
                    <tr key={u.id}>
                      <td>{u.id}</td>
                      <td>{u.name}</td>
                      <td>{u.email}</td>
                      <td>
                        <button
                          type="button"
                          className="btn btn-outline-primary btn-sm"
                          onClick={() => selectUser(u)}
                        >
                          Select
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {meta && (
            <nav aria-label="Pagination" className="d-flex align-items-center gap-2">
              <button
                type="button"
                className="btn btn-outline-secondary btn-sm"
                onClick={() => { if (meta.page > 1) void runSearch(meta.page - 1); }}
                disabled={searching || meta.page <= 1}
              >
                Prev
              </button>
              <span>
                Page {meta.page} of {meta.total_pages} • {meta.total} total
              </span>
              <button
                type="button"
                className="btn btn-outline-secondary btn-sm"
                onClick={() => { if (meta.page < meta.total_pages) void runSearch(meta.page + 1); }}
                disabled={searching || meta.page >= meta.total_pages}
              >
                Next
              </button>
            </nav>
          )}
        </div>
      </section>

      <hr className="my-4" />

      <section aria-labelledby="lookup">
        <h2 id="lookup" className="h5">Lookup by ID</h2>
        <div className="row g-2 align-items-end">
          <div className="col-auto">
            <label htmlFor="user_id" className="form-label">User ID</label>
            <input
              id="user_id"
              type="number"
              inputMode="numeric"
              className="form-control"
              value={userIdInput}
              onChange={(e) => setUserIdInput(e.currentTarget.value)}
              aria-describedby="user_id_help"
            />
            <div id="user_id_help" className="form-text">Enter a numeric user id.</div>
          </div>
          <div className="col-auto">
            <button
              type="button"
              className="btn btn-primary"
              disabled={loadingUser}
              onClick={loadUser}
            >
              {loadingUser ? "Loading…" : "Load"}
            </button>
          </div>
        </div>
      </section>

      <div aria-live="polite" role="status" className="mt-3">
        {msg && <div className="alert alert-info py-2 mb-2">{msg}</div>}
      </div>

      {user && (
        <section className="mt-4" aria-labelledby="user_section">
          <h2 id="user_section" className="h5">User</h2>
          <div className="card mb-3">
            <div className="card-body">
              <p className="mb-1"><strong>ID:</strong> {user.id}</p>
              <p className="mb-1"><strong>Name:</strong> {user.name}</p>
              <p className="mb-3"><strong>Email:</strong> {user.email}</p>

              <div className="mb-3">
                <label htmlFor="attach_role" className="form-label">Attach role</label>
                <div className="d-flex gap-2">
                  <select
                    id="attach_role"
                    className="form-select"
                    value={attachChoice}
                    onChange={(e) => setAttachChoice(e.currentTarget.value)}
                    disabled={loadingRoles || attachable.length === 0}
                    aria-disabled={loadingRoles || attachable.length === 0}
                  >
                    <option value="">Select role…</option>
                    {attachable.map((r) => (
                      <option key={r} value={r}>{r}</option>
                    ))}
                  </select>
                  <button
                    ref={attachBtnRef}
                    type="button"
                    className="btn btn-success"
                    onClick={attachRole}
                    disabled={!attachChoice}
                  >
                    Attach
                  </button>
                </div>
                {loadingRoles && <div className="form-text">Loading roles…</div>}
              </div>

              <div className="mb-4">
                <h3 className="h6">Current roles</h3>
                <ul className="list-unstyled" role="list">
                  {userRoles.length === 0 && <li className="text-muted">None</li>}
                  {userRoles.map((r) => (
                    <li key={r} className="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
                      <span>{r}</span>
                      <button
                        type="button"
                        className="btn btn-outline-danger btn-sm"
                        onClick={() => detachRole(r)}
                        aria-label={`Detach ${r}`}
                      >
                        Detach
                      </button>
                    </li>
                  ))}
                </ul>
              </div>

              <div>
                <label htmlFor="replace_roles" className="form-label">Replace roles</label>
                <div className="row g-2">
                  <div className="col-12 col-md-8">
                    <select
                      id="replace_roles"
                      className="form-select"
                      multiple
                      size={Math.max(4, Math.min(8, allRoles.length || 4))}
                      value={replaceSelection}
                      onChange={onReplaceSelectChange}
                      aria-describedby="replace_help"
                    >
                      {allRoles.map((r) => (
                        <option key={r} value={r}>{r}</option>
                      ))}
                    </select>
                    <div id="replace_help" className="form-text">
                      Select one or more roles. Use Ctrl/Cmd-click to toggle selections.
                    </div>
                  </div>
                  <div className="col-12 col-md-4 d-flex align-items-start">
                    <button
                      type="button"
                      className="btn btn-warning"
                      onClick={doReplace}
                      disabled={
                        replaceBusy ||
                        arraysEqualUnordered(replaceSelection, userRoles)
                      }
                      aria-busy={replaceBusy}
                    >
                      {replaceBusy ? "Replacing…" : "Replace"}
                    </button>
                  </div>
                </div>
                <p className="text-muted mt-2 mb-0">
                  Replaces the user&apos;s roles with exactly the selected set.
                </p>
              </div>
            </div>
          </div>
        </section>
      )}
    </main>
  );
}
