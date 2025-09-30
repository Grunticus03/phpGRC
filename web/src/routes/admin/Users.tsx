import { useCallback, useEffect, useMemo, useState } from "react";
import { apiDelete, apiGet, apiPatch, apiPost, HttpError } from "../../lib/api";

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

function isPagedUsers(x: unknown): x is Paged<User> {
  if (!x || typeof x !== "object") return false;
  const o = x as Record<string, unknown>;
  const m = o.meta as Record<string, unknown> | undefined;
  return o.ok === true && Array.isArray(o.data) && !!m && typeof m.page === "number" && typeof m.total_pages === "number";
}

export default function Users() {
  const [q, setQ] = useState<string>("");
  const [page, setPage] = useState<number>(1);
  const [perPage] = useState<number>(25);
  const [items, setItems] = useState<User[]>([]);
  const [meta, setMeta] = useState<Paged<User>["meta"]>({ page: 1, per_page: 25, total: 0, total_pages: 0 });
  const [busy, setBusy] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const canPrev = useMemo(() => meta.page > 1, [meta.page]);
  const canNext = useMemo(() => meta.page < meta.total_pages, [meta.page, meta.total_pages]);

  const load = useCallback(async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await apiGet<unknown>("/admin/users", { q, page, per_page: perPage });
      if (isPagedUsers(res)) {
        setItems(res.data);
        setMeta(res.meta);
      } else {
        setItems([]);
        setError("Users API shape invalid or not implemented.");
      }
    } catch (err) {
      if (err instanceof HttpError) {
        if (err.status === 401) setError("Unauthorized. Please sign in.");
        else if (err.status === 403) setError("Forbidden. You lack permission to view users.");
        else if (err.status === 404) setError("Not found. Users API is disabled or hidden.");
        else setError(`Request failed: HTTP ${err.status}`);
      } else {
        setError("Load failed");
      }
    } finally {
      setBusy(false);
    }
  }, [q, page, perPage]);

  useEffect(() => {
    void load();
  }, [load]);

  async function onCreate(ev: React.FormEvent<HTMLFormElement>) {
    ev.preventDefault();
    const formEl = ev.currentTarget; // keep reference before await
    const form = new FormData(formEl);
    const payload = {
      name: String(form.get("name") ?? ""),
      email: String(form.get("email") ?? ""),
      password: String(form.get("password") ?? ""),
      roles: (String(form.get("roles") ?? "")
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean)) as string[],
    };
    setBusy(true);
    setError(null);
    try {
      await apiPost<UserResponse>("/admin/users", payload);
      formEl.reset();
      setPage(1);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Create failed");
    } finally {
      setBusy(false);
    }
  }

  async function onUpdate(u: User) {
    const name = prompt("Name", u.name);
    if (name === null) return;
    const email = prompt("Email", u.email);
    if (email === null) return;
    const rolesInput = prompt("Roles (comma-separated)", u.roles.join(", "));
    if (rolesInput === null) return;
    const payload = {
      name,
      email,
      roles: rolesInput.split(",").map((s) => s.trim()).filter(Boolean),
    };
    setBusy(true);
    setError(null);
    try {
      await apiPatch<UserResponse>(`/admin/users/${u.id}`, payload);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Update failed");
    } finally {
      setBusy(false);
    }
  }

  async function onDelete(u: User) {
    if (!confirm(`Delete ${u.email}?`)) return;
    setBusy(true);
    setError(null);
    try {
      await apiDelete<{ ok: true }>(`/admin/users/${u.id}`);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Delete failed");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="p-4 space-y-6">
      <h1 className="text-2xl font-semibold">Users</h1>

      <form className="flex gap-2 items-center" onSubmit={(ev) => (ev.preventDefault(), setPage(1), void load())}>
        <input
          name="q"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search name or email"
          className="border rounded px-3 py-2 w-80"
        />
        <button
          type="submit"
          disabled={busy}
          className="px-3 py-2 rounded border disabled:opacity-50"
          aria-disabled={busy}
        >
          Search
        </button>
      </form>

      {error && <div className="text-red-600 text-sm" role="alert">{error}</div>}

      <div className="border rounded">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-gray-50 text-left">
              <th className="p-2">ID</th>
              <th className="p-2">Name</th>
              <th className="p-2">Email</th>
              <th className="p-2">Roles</th>
              <th className="p-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            {items.map((u) => (
              <tr key={u.id} className="border-t">
                <td className="p-2">{u.id}</td>
                <td className="p-2">{u.name}</td>
                <td className="p-2">{u.email}</td>
                <td className="p-2">{u.roles.join(", ")}</td>
                <td className="p-2 space-x-2">
                  <button type="button" className="px-2 py-1 border rounded" onClick={() => { void onUpdate(u); }}>
                    Edit
                  </button>
                  <button
                    type="button"
                    className="px-2 py-1 border rounded text-red-700"
                    onClick={() => { void onDelete(u); }}
                  >
                    Delete
                  </button>
                </td>
              </tr>
            ))}
            {items.length === 0 && !busy && (
              <tr>
                <td className="p-4 text-center text-gray-500" colSpan={5}>
                  No users
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="flex items-center gap-2">
        <button
          type="button"
          className="px-3 py-1 border rounded disabled:opacity-50"
          onClick={() => setPage((p) => Math.max(1, p - 1))}
          disabled={!canPrev || busy}
        >
          Prev
        </button>
        <div className="text-sm">
          Page {meta.page} / {Math.max(1, meta.total_pages)}
        </div>
        <button
          type="button"
          className="px-3 py-1 border rounded disabled:opacity-50"
          onClick={() => setPage((p) => p + 1)}
          disabled={!canNext || busy}
        >
          Next
        </button>
      </div>

      <div className="border rounded p-4">
        <h2 className="font-medium mb-2">Create user</h2>
        <form onSubmit={onCreate} className="grid gap-3 max-w-md">
          <input name="name" placeholder="Name" required className="border rounded px-3 py-2" />
          <input name="email" type="email" placeholder="Email" required className="border rounded px-3 py-2" />
          <input name="password" type="password" placeholder="Password" required className="border rounded px-3 py-2" />
          <input
            name="roles"
            placeholder="Roles (comma-separated)"
            className="border rounded px-3 py-2"
            aria-label="Roles (comma-separated)"
          />
          <button type="submit" disabled={busy} className="px-3 py-2 border rounded disabled:opacity-50">
            Create
          </button>
        </form>
      </div>
    </div>
  );
}
