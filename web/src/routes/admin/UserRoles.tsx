import React, { useEffect, useMemo, useRef, useState } from "react";

type User = { id: number; name: string; email: string };
type UserRolesEnvelope =
  | { ok: true; user: User; roles: string[] }
  | { ok: false; code: string; missing_roles?: string[] };
type RolesListEnvelope = { ok: true; roles: string[] };

function json<T>(res: Response): Promise<T> {
  return res.json() as Promise<T>;
}

export default function UserRoles(): JSX.Element {
  const [allRoles, setAllRoles] = useState<string[]>([]);
  const [loadingRoles, setLoadingRoles] = useState<boolean>(false);

  const [userIdInput, setUserIdInput] = useState<string>("");
  const [loadingUser, setLoadingUser] = useState<boolean>(false);
  const [user, setUser] = useState<User | null>(null);
  const [userRoles, setUserRoles] = useState<string[]>([]);

  const [attachChoice, setAttachChoice] = useState<string>("");
  const [msg, setMsg] = useState<string | null>(null);
  const attachBtnRef = useRef<HTMLButtonElement | null>(null);

  // Load role catalog once
  useEffect(() => {
    const ctl = new AbortController();
    (async () => {
      setLoadingRoles(true);
      try {
        const res = await fetch("/api/rbac/roles", {
          method: "GET",
          credentials: "same-origin",
          signal: ctl.signal,
        });
        if (!res.ok) throw new Error(String(res.status));
        const data = await json<RolesListEnvelope>(res);
        if (data.ok) setAllRoles(data.roles ?? []);
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
    const idNum = Number(userIdInput);
    if (!Number.isInteger(idNum) || idNum <= 0) {
      setMsg("Enter a valid User ID.");
      return;
    }
    setLoadingUser(true);
    try {
      const res = await fetch(`/api/rbac/users/${idNum}/roles`, {
        method: "GET",
        credentials: "same-origin",
      });
      const data = await json<UserRolesEnvelope>(res);
      if (res.ok && "ok" in data && data.ok) {
        setUser(data.user);
        setUserRoles(data.roles ?? []);
        setAttachChoice("");
        setMsg(null);
        // focus attach for speed
        queueMicrotask(() => attachBtnRef.current?.focus());
      } else if (!res.ok && "code" in data) {
        setMsg(`Error: ${data.code}`);
      } else {
        setMsg("Lookup failed.");
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
    const res = await fetch(`/api/rbac/users/${user.id}/roles/${encodeURIComponent(attachChoice)}`, {
      method: "POST",
      credentials: "same-origin",
    });
    const data = await json<UserRolesEnvelope>(res);
    if (res.ok && "ok" in data && data.ok) {
      setUserRoles(data.roles ?? []);
      setAttachChoice("");
      setMsg("Role attached.");
    } else if ("code" in data) {
      setMsg(`Attach failed: ${data.code}`);
    } else {
      setMsg("Attach failed.");
    }
  }

  async function detachRole(role: string) {
    if (!user) return;
    setMsg(null);
    const res = await fetch(`/api/rbac/users/${user.id}/roles/${encodeURIComponent(role)}`, {
      method: "DELETE",
      credentials: "same-origin",
    });
    const data = await json<UserRolesEnvelope>(res);
    if (res.ok && "ok" in data && data.ok) {
      setUserRoles(data.roles ?? []);
      setMsg("Role detached.");
    } else if ("code" in data) {
      setMsg(`Detach failed: ${data.code}`);
    } else {
      setMsg("Detach failed.");
    }
  }

  return (
    <main className="container py-3">
      <h1 className="mb-3">User Roles</h1>

      <section aria-labelledby="lookup">
        <h2 id="lookup" className="h5">Lookup</h2>
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

              <div>
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
            </div>
          </div>
        </section>
      )}
    </main>
  );
}
