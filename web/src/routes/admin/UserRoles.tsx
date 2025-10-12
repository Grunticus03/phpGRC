import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  listRoles,
  getUserRoles,
  attachUserRole,
  detachUserRole,
  searchUsers,
  type UserRolesResponse,
  type UserRolesResponseOk,
  type RoleListResponse,
  type UserSummary,
  type UserSearchOk,
} from "../../lib/api/rbac";
import { roleIdsFromNames, roleLabelFromId, roleOptionsFromList, type RoleOption } from "../../lib/roles";

type User = { id: number; name: string; email: string };

export default function UserRoles(): JSX.Element {
  // Role catalog
  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([]);
  const [loadingRoles, setLoadingRoles] = useState<boolean>(false);

  const [loadingUser, setLoadingUser] = useState<boolean>(false);
  const [user, setUser] = useState<User | null>(null);
  const [userRoleIds, setUserRoleIds] = useState<string[]>([]);

  // Attach state
  const [attachChoice, setAttachChoice] = useState<string>("");

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
        if (ctl.signal.aborted) return;
        if (res.ok) {
          setRoleOptions(roleOptionsFromList(res.roles ?? []));
        } else {
          setRoleOptions([]);
          setMsg("Failed to load roles.");
        }
      } catch {
        if (!ctl.signal.aborted) {
          setRoleOptions([]);
          setMsg("Failed to load roles.");
        }
      } finally {
        if (!ctl.signal.aborted) setLoadingRoles(false);
      }
    })();
    return () => ctl.abort();
  }, []);

  const attachable = useMemo(
    () => roleOptions.filter((r) => !userRoleIds.includes(r.id)),
    [roleOptions, userRoleIds]
  );

  useEffect(() => {
    if (attachable.length === 0) {
      if (attachChoice !== "") setAttachChoice("");
      return;
    }
    if (!attachable.some((opt) => opt.id === attachChoice)) {
      setAttachChoice(attachable[0].id);
    }
  }, [attachable, attachChoice]);

  async function attachRole() {
    if (!user) return;
    if (!attachChoice) return;
    setMsg(null);
    try {
      const res = await attachUserRole(user.id, attachChoice);
      if (res.ok) {
        const ok = res as UserRolesResponseOk;
        const roleIds = roleIdsFromNames(ok.roles ?? []);
        setUserRoleIds(roleIds);
        const attachedLabel = roleLabelFromId(attachChoice);
        setAttachChoice("");
        setMsg(attachedLabel ? `${attachedLabel} attached.` : "Role attached.");
      } else {
        setMsg(`Attach failed: ${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setMsg("Attach failed.");
    }
  }

  async function detachRole(roleId: string) {
    if (!user) return;
    setMsg(null);
    try {
      const res = await detachUserRole(user.id, roleId);
      if (res.ok) {
        const ok = res as UserRolesResponseOk;
        const roleIds = roleIdsFromNames(ok.roles ?? []);
        setUserRoleIds(roleIds);
        const detachedLabel = roleLabelFromId(roleId);
        setMsg(detachedLabel ? `${detachedLabel} detached.` : "Role detached.");
      } else {
        setMsg(`Detach failed: ${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setMsg("Detach failed.");
    }
  }

  async function runSearch(targetPage?: number) {
    setUser(null);
    setUserRoleIds([]);
    setAttachChoice("");
    setLoadingUser(false);
    setSearching(true);
    setMsg(null);
    try {
      const p = typeof targetPage === "number" ? targetPage : page;
      const query = q.trim();
      const effectiveQuery = query === "" ? "*" : query;
      const res = await searchUsers(effectiveQuery, p, perPage);
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
    if (loadingUser) return;
    // clear search results to avoid duplicate name text in DOM
    setResults([]);
    setMeta(null);
    // chain into role load
    void loadUserWith(u.id);
  }

  async function loadUserWith(id: number) {
    setMsg(null);
    setUser(null);
    setUserRoleIds([]);
    setLoadingUser(true);
    try {
      const res: UserRolesResponse = await getUserRoles(id);
      if (res.ok) {
        const ok = res as UserRolesResponseOk;
        setUser(ok.user);
        const roleIds = roleIdsFromNames(ok.roles ?? []);
        setUserRoleIds(roleIds);
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
              placeholder="Search name, email, or role"
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
                  </tr>
                </thead>
                <tbody>
                  {results.map((u) => {
                    const selected = user?.id === u.id;
                    return (
                      <tr
                        key={u.id}
                        role="button"
                        tabIndex={0}
                        className={selected ? "table-active" : ""}
                        style={{ cursor: "pointer" }}
                        onClick={() => selectUser(u)}
                        onKeyDown={(event) => {
                          if (event.key === "Enter" || event.key === " ") {
                            event.preventDefault();
                            selectUser(u);
                          }
                        }}
                      >
                        <td>{u.id}</td>
                        <td>{u.name}</td>
                        <td>{u.email}</td>
                      </tr>
                    );
                  })}
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
                    {attachable.map((option) => (
                      <option key={option.id} value={option.id}>{option.name}</option>
                    ))}
                  </select>
                  <button
                    ref={attachBtnRef}
                    type="button"
                    className="btn btn-success"
                    onClick={attachRole}
                    disabled={!attachChoice}
                  >
                    Add
                  </button>
                </div>
                {loadingRoles && <div className="form-text">Loading roles…</div>}
              </div>

              <div className="mb-4">
                <h3 className="h6">Current roles</h3>
                <ul className="list-unstyled" role="list">
                  {userRoleIds.length === 0 && <li className="text-muted">None</li>}
                  {userRoleIds.map((roleId) => {
                    const label = roleLabelFromId(roleId) || roleId;
                    return (
                      <li key={roleId} className="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
                        <span>{label}</span>
                        <button
                          type="button"
                          className="btn btn-outline-danger btn-sm"
                          onClick={() => detachRole(roleId)}
                          aria-label={`Remove ${label}`}
                        >
                          Remove
                        </button>
                      </li>
                    );
                  })}
                </ul>
              </div>
            </div>
          </div>
        </section>
      )}
    </main>
  );
}
