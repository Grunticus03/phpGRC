import { useEffect, useMemo, useRef, useState } from "react";
import { listCategories } from "../../lib/api/audit";

type AuditItem = {
  id?: string;
  ulid?: string;
  created_at?: string;
  occurred_at?: string;
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
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && String(v).length > 0) qs.set(k, String(v));
  }
  return qs.toString();
}

export default function Audit(): JSX.Element {
  const [category, setCategory] = useState("");
  const [action, setAction] = useState("");
  const [occurredFrom, setOccurredFrom] = useState("");
  const [occurredTo, setOccurredTo] = useState("");
  const [order, setOrder] = useState<"asc" | "desc">("desc");
  const [limit, setLimit] = useState(10);

  const [items, setItems] = useState<AuditItem[]>([]);
  const [state, setState] = useState<FetchState>("idle");
  const [error, setError] = useState<string>("");

  const [categories, setCategories] = useState<string[]>([]);
  const [catsLoading, setCatsLoading] = useState<boolean>(false);

  const ctrl = useRef<AbortController | null>(null);

  // Load category enums
  useEffect(() => {
    const ac = new AbortController();
    setCatsLoading(true);
    listCategories(ac.signal)
      .then((list) => setCategories(list))
      .finally(() => setCatsLoading(false));
    return () => ac.abort();
  }, []);

  const query = useMemo(
    () =>
      buildQuery({
        category: category || undefined,
        action: action || undefined,
        occurred_from: occurredFrom || undefined,
        occurred_to: occurredTo || undefined,
        order,
        limit,
      }),
    [category, action, occurredFrom, occurredTo, order, limit]
  );

  async function load() {
    try {
      setState("loading");
      setError("");
      ctrl.current?.abort();
      ctrl.current = new AbortController();
      const res = await fetch(`/api/audit?${query}`, { signal: ctrl.current.signal, credentials: "same-origin" });
      if (!res.ok) {
        setState("error");
        setError(`HTTP ${res.status}`);
        return;
      }
      const data = await res.json();
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
          <select
            id="f-cat"
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            disabled={catsLoading}
            aria-busy={catsLoading}
          >
            <option value="">All</option>
            {categories.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label htmlFor="f-act">Action</label>
          <input id="f-act" value={action} onChange={(e) => setAction(e.target.value)} placeholder="e.g. rbac.user_role.attached" />
        </div>

        <div>
          <label htmlFor="f-from">Occurred from</label>
          <input id="f-from" type="date" value={occurredFrom} onChange={(e) => setOccurredFrom(e.target.value)} />
        </div>

        <div>
          <label htmlFor="f-to">Occurred to</label>
          <input id="f-to" type="date" value={occurredTo} onChange={(e) => setOccurredTo(e.target.value)} />
        </div>

        <div>
          <label htmlFor="f-order">Order</label>
          <select id="f-order" value={order} onChange={(e) => setOrder(e.target.value as "asc" | "desc")}>
            <option value="desc">desc</option>
            <option value="asc">asc</option>
          </select>
        </div>

        <div>
          <label htmlFor="f-limit">Limit</label>
          <input
            id="f-limit"
            type="number"
            min={1}
            max={100}
            step={1}
            value={limit}
            onChange={(e) => {
              const n = Number(e.target.value || 10);
              setLimit(Number.isFinite(n) ? Math.max(1, Math.min(100, n)) : 10);
            }}
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
      {state === "error" && (
        <p role="alert" aria-live="assertive">
          Error: {error}
        </p>
      )}

      <div style={{ overflowX: "auto" }}>
        <table aria-label="Audit events" className="table">
          <thead>
            <tr
