import { useEffect, useMemo, useState } from "react";
import {
  listRoles,
  getUserRoles,
  replaceUserRoles,
  attachUserRole,
  detachUserRole,
  RoleListResponse,
  UserRolesResponseOk,
  UserRolesResponse,
} from "../../lib/api/rbac";

type LoadState =
  | { kind: "idle" }
  | { kind: "loading" }
  | { kind: "loaded"; data: UserRolesResponseOk }
  | { kind: "error"; message: string };

export default function UserRoles(): JSX.Element {
  const [available, setAvailable] = useState<string[]>([]);
  const [state, setState] = useState<LoadState>({ kind: "idle" });
  const [userIdInput, setUserIdInput] = useState<string>("");
  const [msg, setMsg] = useState<string | null>(null);
  const [working, setWorking] = useState<boolean>(false);
  const [pick, setPick] = useState<string>("");

  useEffect(() => {
    void (async () => {
      const res: RoleListResponse = await listRoles();
      if (res.ok) setAvailable(res.roles);
      else setMsg("Failed to load available roles.");
    })();
  }, []);

  const current = state.kind === "loaded" ? state.data : null;

  const addChoices = useMemo(() => {
    if (!current) return available;
    const assigned = new Set(current.roles);
    return available.filter((r) => !assigned.has(r));
  }, [available, current]);

  async function lookupUser(e: React.FormEvent) {
    e.preventDefault();
    setMsg(null);
    setPick("");
    setState({ kind: "loading" });
    setWorking(true);
    try {
      const id = Number(userIdInput);
      if (!Number.isInteger(id) || id < 1) {
        setState({ kind: "error", message: "Enter a valid numeric user ID." });
        return;
      }
      const res: UserRolesResponse = await getUserRoles(id);
      if (res.ok) {
        setState({ kind: "loaded", data: res });
      } else if (res.code === "FORBIDDEN") {
        setState({ kind: "error", message: "Forbidden." });
      } else {
        setState({ kind: "error", message: "User not found or request failed." });
      }
    } finally {
      setWorking(false);
    }
  }

  async function onReplace(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!current) return;
    setWorking(true);
    setMsg(null);
    try {
      const form = new FormData(e.currentTarget);
      const roles = form.getAll("roles") as string[];
      const res = await replaceUserRoles(current.user.id, roles);
      if (res.ok) {
        setState({ kind: "loaded", data: res });
        setMsg("Replaced roles.");
      } else if (res.code === "ROLE_NOT_FOUND") {
        setMsg("One or more roles not found.");
      } else if (res.code === "FORBIDDEN") {
        setMsg("Forbidden.");
      } else {
        setMsg("Replace failed.");
      }
    } finally {
      setWorking(false);
    }
  }

  async function onAttach() {
    if (!current || !pick) return;
    setWorking(true);
    setMsg(null);
    try {
      const res = await attachUserRole(current.user.id, pick);
      if (res.ok) {
        setState({ kind: "loaded", data: res });
        setPick("");
        setMsg("Attached role.");
      } else if (res.code === "ROLE_NOT_FOUND") {
        setMsg("Role not found.");
      } else if (res.code === "FORBIDDEN") {
        setMsg("Forbidden.");
      } else {
        setMsg("Attach failed.");
      }
    } finally {
      setWorking(false);
    }
  }

  async function onDetach(role: string) {
    if (!current) return;
    setWorking(true);
    setMsg(null);
    try {
      const res = await detachUserRole(current.user.id, role);
      if (res.ok) {
        setState({ kind: "loaded", data: res });
        setMsg("Detached role.");
      } else if (res.code === "FORBIDDEN") {
        setMsg("Forbidden.");
      } else {
        setMsg("Detach failed.");
      }
    } finally {
      setWorking(false);
    }
  }

  if (state.kind === "loading")
    return (
      <div className="container py-5" role="status" aria-live="polite" aria-busy="true">
        <div className="spinner-border" aria-hidden="true"></div>
        <span className="visually-hidden">Loading</span>
      </div>
    );

  return (
    <main id="main" className="container py-3" role="main" aria-busy={working}>
      <h1 className="mb-3">User Roles</h1>

      <form className="row gy-2 align-items-end mb-3" onSubmit={lookupUser} noValidate aria-busy={working}>
        <div className="col-auto">
          <label htmlFor="uid" className="form-label">
            User ID
          </label>
          <input
            id="uid"
            className="form-control"
            value={userIdInput}
            inputMode="numeric"
            onChange={(e) => setUserIdInput(e.currentTarget.value)}
            placeholder="e.g., 1"
            aria-describedby="uidHelp"
          />
          <div id="uidHelp" className="form-text">
            Enter a numeric user ID.
          </div>
        </div>
        <div className="col-auto">
          <button className="btn btn-primary" type="submit" disabled={working}>
            Load
          </button>
        </div>
      </form>

      <div aria-live="polite" role="status">
        {msg && <div className="alert alert-info">{msg}</div>}
      </div>

      {state.kind === "error" && <div className="alert alert-warning" role="alert">{state.message}</div>}

      {current && (
        <div className="card p-3" aria-busy={working}>
          <h2 className="h5 mb-3">User</h2>
          <div className="mb-3">
            <div>
              <strong>ID:</strong> {current.user.id}
            </div>
            <div>
              <strong>Name:</strong> {current.user.name}
            </div>
            <div>
              <strong>Email:</strong> {current.user.email}
            </div>
          </div>

          <h3 className="h6">Current roles</h3>
          {current.roles.length === 0 ? (
            <p className="text-muted">No roles assigned.</p>
          ) : (
            <ul className="list-group mb-3">
              {current.roles.map((r) => (
                <li key={r} className="list-group-item d-flex justify-content-between align-items-center">
                  <span>{r}</span>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-danger"
                    onClick={() => void onDetach(r)}
                    disabled={working}
                    aria-label={`Detach ${r}`}
                  >
                    Detach
                  </button>
                </li>
              ))}
            </ul>
          )}

          <div className="mb-3 d-flex gap-2 align-items-end">
            <div>
              <label className="form-label" htmlFor="attachPick">
                Attach role
              </label>
              <select
                id="attachPick"
                className="form-select"
                value={pick}
                onChange={(e) => setPick(e.target.value)}
              >
                <option value="">Select role…</option>
                {addChoices.map((r) => (
                  <option key={r} value={r}>
                    {r}
                  </option>
                ))}
              </select>
            </div>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={() => void onAttach()}
              disabled={!pick || working}
            >
              Attach
            </button>
          </div>

          <h3 className="h6">Replace roles</h3>
          <form onSubmit={onReplace} noValidate aria-busy={working}>
            <div className="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2">
              {available.map((r) => {
                const checked = current.roles.includes(r);
                return (
                  <div className="col" key={r}>
                    <label className="form-check">
                      <input
                        className="form-check-input"
                        type="checkbox"
                        name="roles"
                        value={r}
                        defaultChecked={checked}
                      />
                      <span className="form-check-label">{r}</span>
                    </label>
                  </div>
                );
              })}
            </div>
            <div className="mt-3">
              <button className="btn btn-primary" type="submit" disabled={working}>
                Replace
              </button>
            </div>
          </form>
        </div>
      )}
    </main>
  );
}
