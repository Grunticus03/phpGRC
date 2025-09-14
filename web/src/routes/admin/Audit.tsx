import { useEffect, useMemo, useRef, useState } from "react";

type AuditItem = {
  id?: string;
  ulid?: string;
  created_at?: string;
  ts?: string;
  category?: string;
  action?: string;
  user_id?: number | string | null;
  actor_id?: number | string | null;
  ip?: string | null;
  note?: string | null;
  [k: string]: unknown;
};

type FetchState = "idle" | "loading" | "error" | "ok";

function buildQuery(params: Record<string, string | number | undefined>) {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && String(v).length > 0) qs.set(k, String(v));
  });
  return qs.toString();
}

export default function Audit(): JSX.Element {
  const [category, setCategory] = useState("");
  const [action, setAction] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [limit, setLimit] = useState(10);

  const [items, setItems] = useState<AuditItem[]>([]);
  const [state, setState] = useState<FetchState>("idle");
  const [error, setError] = useState<string>("");

  const ctrl = useRef<AbortController | null>(null);

  const query = useMemo(
    () =>
      buildQuery({
        category: category || undefined,
        action: action || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        limit,
      }),
    [category, action, dateFrom, dateTo, limit]
  );

  async function load() {
    try {
      setState("loading");
      setError("");
      ctrl.current?.abort();
      ctrl.current = new AbortController();
      const res = await fetch(`/api/audit?${query}`, { signal: ctrl.current.signal });
      if (!res.ok) {
        setState("error");
        setError(`HTTP ${res.status}`);
        return;
      }
      const data = await res.json();
      // Accept array or wrapped payloads
      const list: AuditItem[] = Array.isArray(data)
        ? data
        : Array.isArray(data.items)
        ? data.items
        : Array.isArray(data.data)
        ? data.data
        : [];
      setItems(list);
      setState("ok");
    } catch (e: any) {
      if (e?.name === "AbortError") return;
      setState("error");
      setError(e?.message ?? "Request failed");
    }
  }

  useEffect(() => {
    // initial load
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const csvHref = useMemo(() => `/api/audit/export.csv?${query}`, [query]);

  return (
    <section>
      <h1>Audit</h1>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          void load();
        }}
        style={{ display: "grid", gap: "0.75rem", gridTemplateColumns: "repeat(auto-fit,minmax(180px,1fr))", marginBottom: "1rem" }}
        aria-label="Audit filters"
      >
        <div>
          <label htmlFor="f-cat">Category</label>
          <input id="f-cat" value={category} onChange={(e) => setCategory(e.target.value)} placeholder="e.g. rbac" />
        </div>
        <div>
          <label htmlFor="f-act">Action</label>
          <input id="f-act" value={action} onChange={(e) => setAction(e.target.value)} placeholder="e.g. assign" />
        </div>
        <div>
          <label htmlFor="f-from">From</label>
          <input id="f-from" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
        </div>
        <div>
          <label htmlFor="f-to">To</label>
          <input id="f-to" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
        </div>
        <div>
          <label htmlFor="f-limit">Limit</label>
          <input
            id="f-limit"
            type="number"
            min={1}
            max={500}
            step={1}
            value={limit}
            onChange={(e) => setLimit(Number(e.target.value || 10))}
          />
        </div>
        <div style={{ alignSelf: "end" }}>
          <button type="submit">Apply</button>
          <a href={csvHref} style={{ marginLeft: "0.5rem" }}>
            Download CSV
          </a>
        </div>
      </form>

      {state === "loading" && <p>Loading…</p>}
      {state === "error" && <p role="alert">Error: {error}</p>}

      <div style={{ overflowX: "auto" }}>
        <table aria-label="Audit events" className="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Timestamp</th>
              <th>Category</th>
              <th>Action</th>
              <th>User</th>
              <th>IP</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 && state === "ok" ? (
              <tr>
                <td colSpan={7}>No results</td>
              </tr>
            ) : (
              items.map((it, i) => {
                const id = (it.id as string) || (it.ulid as string) || String(i);
                const ts = it.created_at || it.ts || "";
                const user = it.user_id ?? it.actor_id ?? "";
                return (
                  <tr key={id}>
                    <td>{id}</td>
                    <td>{String(ts)}</td>
                    <td>{String(it.category ?? "")}</td>
                    <td>{String(it.action ?? "")}</td>
                    <td>{String(user)}</td>
                    <td>{String(it.ip ?? "")}</td>
                    <td>{String(it.note ?? "")}</td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}
