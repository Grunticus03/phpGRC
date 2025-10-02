import { useEffect, useMemo, useRef, useState } from "react";
import { listCategories } from "../../lib/api/audit";
import { actionInfo } from "../../lib/auditLabels";
import { searchUsers, type UserSummary, type UserSearchOk } from "../../lib/api/rbac";
import { apiGet, type QueryInit } from "../../lib/api";

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
type FieldErrors = Record<string, string[]>;

function buildQuery(params: Record<string, string | number | undefined>) {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && String(v).length > 0) qs.set(k, String(v));
  });
  return qs.toString();
}

function isAbortError(err: unknown): boolean {
  if (err instanceof DOMException) return err.name === "AbortError";
  return typeof err === "object" && err !== null && (err as { name?: unknown }).name === "AbortError";
}

function getErrorMessage(err: unknown): string {
  if (err instanceof Error) return err.message;
  const msg = (err as { message?: unknown })?.message;
  return typeof msg === "string" ? msg : "Request failed";
}

function parse422(json: unknown): FieldErrors {
  if (!json || typeof json !== "object") return {};
  const obj = json as Record<string, unknown>;
  const errors = obj.errors ?? obj.error ?? obj._errors;
  if (!errors || typeof errors !== "object") return {};
  const out: FieldErrors = {};
  Object.entries(errors as Record<string, unknown>).forEach(([k, v]) => {
    if (Array.isArray(v)) out[k] = v.filter((x): x is string => typeof x === "string");
    else if (typeof v === "string") out[k] = [v];
  });
  return out;
}

function chipStyle(variant: "neutral" | "success" | "warning" | "danger"): React.CSSProperties {
  const base: React.CSSProperties = {
    display: "inline-block",
    fontSize: "0.85rem",
    padding: "0.15rem 0.5rem",
    borderRadius: "999px",
    border: "1px solid transparent",
    lineHeight: 1.2,
    whiteSpace: "nowrap",
  };
  switch (variant) {
    case "success":
      return { ...base, color: "#0f5132", backgroundColor: "#d1e7dd", borderColor: "#badbcc" };
    case "warning":
      return { ...base, color: "#664d03", backgroundColor: "#fff3cd", borderColor: "#ffecb5" };
    case "danger":
      return { ...base, color: "#842029", backgroundColor: "#f8d7da", borderColor: "#f5c2c7" };
    default:
      return { ...base, color: "#0c5460", backgroundColor: "#e2f0f3", borderColor: "#bee5eb" };
  }
}

export default function Audit(): JSX.Element {
  const [category, setCategory] = useState("");
  const [action, setAction] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [limit, setLimit] = useState(10);
  const [categories, setCategories] = useState<string[]>([]);

  // Actor picker state
  const [actorQ, setActorQ] = useState("");
  const [actorSearching, setActorSearching] = useState(false);
  const [actorResults, setActorResults] = useState<UserSummary[]>([]);
  const [actorMeta, setActorMeta] = useState<{ page: number; total_pages: number } | null>(null);
  const [selectedActor, setSelectedActor] = useState<UserSummary | null>(null);

  const [items, setItems] = useState<AuditItem[]>([]);
  const [state, setState] = useState<FetchState>("idle");
  const [error, setError] = useState<string>("");
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});

  const [lastAppliedQuery, setLastAppliedQuery] = useState<string>("");
  const ctrl = useRef<AbortController | null>(null);

  const occurred_from = dateFrom ? `${dateFrom}T00:00:00Z` : undefined;
  const occurred_to = dateTo ? `${dateTo}T23:59:59Z` : undefined;

  const query = useMemo(
    () =>
      buildQuery({
        category: category || undefined,
        action: action || undefined,
        occurred_from,
        occurred_to,
        limit,
        actor_id: selectedActor ? selectedActor.id : undefined,
      }),
    [category, action, occurred_from, occurred_to, limit, selectedActor]
  );

  const isDateOrderValid = useMemo(() => {
    if (!dateFrom || !dateTo) return true;
    return dateFrom <= dateTo;
  }, [dateFrom, dateTo]);

  const isDirty = query !== lastAppliedQuery;
  const canSubmit = isDirty && isDateOrderValid && state !== "loading";

  async function load() {
    try {
      setState("loading");
      setError("");
      setFieldErrors({});
      ctrl.current?.abort();
      ctrl.current = new AbortController();

      const params: QueryInit = {
        category: category || undefined,
        action: action || undefined,
        occurred_from,
        occurred_to,
        limit,
        actor_id: selectedActor ? selectedActor.id : undefined,
      };

      const data = await apiGet<unknown>("/api/audit", params, ctrl.current.signal);
      let list: AuditItem[] = [];
      if (Array.isArray(data)) {
        list = data as AuditItem[];
      } else if (data && typeof data === "object") {
        const o = data as Record<string, unknown>;
        if (Array.isArray(o.items)) list = o.items as AuditItem[];
        else if (Array.isArray(o.data)) list = o.data as AuditItem[];
      }

      setItems(list);
      setState("ok");
      setLastAppliedQuery(query);
    } catch (err: unknown) {
      if (isAbortError(err)) return;
      if (err && typeof err === "object" && (err as { status?: unknown }).status === 422) {
        const body = (err as { body?: unknown }).body;
        const fe = parse422(body);
        setFieldErrors(fe);
        setState("error");
        setError("Validation error");
        return;
      }
      setState("error");
      setError(getErrorMessage(err));
    }
  }

  useEffect(() => {
    void (async () => {
      const ab = new AbortController();
      try {
        const arr = await listCategories(ab.signal);
        setCategories(arr);
      } finally {
        // no-op
      }
      await load();
      return () => ab.abort();
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const csvHref = useMemo(() => `/api/audit/export.csv?${query}`, [query]);

  async function runActorSearch(targetPage?: number) {
    setActorSearching(true);
    try {
      const page = typeof targetPage === "number" ? targetPage : 1;
      const res = await searchUsers(actorQ, page, 10);
      if (res.ok) {
        const ok = res as UserSearchOk;
        setActorResults(ok.data);
        setActorMeta({ page: ok.meta.page, total_pages: ok.meta.total_pages });
      } else {
        setActorResults([]);
        setActorMeta(null);
      }
    } finally {
      setActorSearching(false);
    }
  }

  function selectActor(u: UserSummary) {
    setSelectedActor(u);
    setActorResults([]);
    setActorMeta(null);
  }

  function clearActor() {
    setSelectedActor(null);
    setActorResults([]);
    setActorMeta(null);
    setActorQ("");
  }

  return (
    <section aria-busy={state === "loading"}>
      <h1>Audit</h1>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (!isDateOrderValid) {
            setFieldErrors((prev) => ({
              ...prev,
              occurred_from: ["From must be on or before To"],
              occurred_to: ["To must be on or after From"],
            }));
            return;
          }
          void load();
        }}
        style={{
          display: "grid",
          gap: "0.75rem",
          gridTemplateColumns: "repeat(auto-fit,minmax(180px,1fr))",
          marginBottom: "1rem",
        }}
        aria-label="Audit filters"
      >
        <div>
          <label htmlFor="f-cat">Category</label>
          {categories.length > 0 ? (
            <select
              id="f-cat"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              aria-invalid={!!fieldErrors.category?.length}
            >
              <option value="">(any)</option>
              {categories.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
          ) : (
            <input
              id="f-cat"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              placeholder="e.g. RBAC"
              aria-invalid={!!fieldErrors.category?.length}
            />
          )}
          {fieldErrors.category?.length ? (
            <ul role="alert" style={{ margin: "0.25rem 0 0 0", paddingLeft: "1rem" }}>
              {fieldErrors.category.map((m, i) => (
                <li key={i}>{m}</li>
              ))}
            </ul>
          ) : null}
        </div>

        <div>
          <label htmlFor="f-act">Action</label>
          <input
            id="f-act"
            value={action}
            onChange={(e) => setAction(e.target.value)}
            placeholder="e.g. rbac.user_role.attached"
            aria-invalid={!!fieldErrors.action?.length}
          />
          {fieldErrors.action?.length ? (
            <ul role="alert" style={{ margin: "0.25rem 0 0 0", paddingLeft: "1rem" }}>
              {fieldErrors.action.map((m, i) => (
                <li key={i}>{m}</li>
              ))}
            </ul>
          ) : null}
        </div>

        <div>
          <label htmlFor="f-from">From</label>
          <input
            id="f-from"
            type="date"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
            aria-invalid={!isDateOrderValid || !!fieldErrors.occurred_from?.length}
          />
          {!isDateOrderValid ? <p role="alert" style={{ margin: "0.25rem 0 0 0" }}>From must be ≤ To</p> : null}
          {fieldErrors.occurred_from?.length ? (
            <ul role="alert" style={{ margin: "0.25rem 0 0 0", paddingLeft: "1rem" }}>
              {fieldErrors.occurred_from.map((m, i) => (
                <li key={i}>{m}</li>
              ))}
            </ul>
          ) : null}
        </div>

        <div>
          <label htmlFor="f-to">To</label>
          <input
            id="f-to"
            type="date"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
            aria-invalid={!isDateOrderValid || !!fieldErrors.occurred_to?.length}
          />
          {!isDateOrderValid ? <p role="alert" style={{ margin: "0.25rem 0 0 0" }}>To must be ≥ From</p> : null}
          {fieldErrors.occurred_to?.length ? (
            <ul role="alert" style={{ margin: "0.25rem 0 0 0", paddingLeft: "1rem" }}>
              {fieldErrors.occurred_to.map((m, i) => (
                <li key={i}>{m}</li>
              ))}
            </ul>
          ) : null}
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
            onChange={(e) => setLimit(Number(e.target.value || 10))}
            aria-invalid={!!fieldErrors.limit?.length}
          />
          {fieldErrors.limit?.length ? (
            <ul role="alert" style={{ margin: "0.25rem 0 0 0", paddingLeft: "1rem" }}>
              {fieldErrors.limit.map((m, i) => (
                <li key={i}>{m}</li>
              ))}
            </ul>
          ) : null}
        </div>

        <div>
          <label htmlFor="f-actor">Actor</label>
          {selectedActor ? (
            <div>
              <div style={{ display: "flex", gap: "0.5rem", alignItems: "center" }}>
                <span>{selectedActor.name} &lt;{selectedActor.email}&gt; (id {selectedActor.id})</span>
                <button type="button" onClick={clearActor}>Clear</button>
              </div>
            </div>
          ) : (
            <div style={{ display: "grid", gap: "0.25rem" }}>
              <input
                id="f-actor"
                value={actorQ}
                onChange={(e) => setActorQ(e.target.value)}
                placeholder="search name or email"
              />
              <div style={{ display: "flex", gap: "0.5rem" }}>
                <button type="button" onClick={() => void runActorSearch()} aria-busy={actorSearching}>
                  {actorSearching ? "Searching…" : "Search"}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setActorResults([]);
                    setActorMeta(null);
                    setActorQ("");
                  }}
                >
                  Reset
                </button>
              </div>
              {actorResults.length > 0 && (
                <div style={{ overflowX: "auto" }}>
                  <table className="table">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th />
                      </tr>
                    </thead>
                    <tbody>
                      {actorResults.map((u) => (
                        <tr key={u.id}>
                          <td>{u.id}</td>
                          <td>{u.name}</td>
                          <td>{u.email}</td>
                          <td>
                            <button type="button" onClick={() => selectActor(u)}>Select</button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {actorMeta && (
                    <div style={{ display: "flex", gap: "0.5rem", alignItems: "center" }}>
                      <button
                        type="button"
                        onClick={() => actorMeta.page > 1 && void runActorSearch(actorMeta.page - 1)}
                        disabled={actorSearching || actorMeta.page <= 1}
                      >
                        Prev
                      </button>
                      <span>
                        Page {actorMeta.page} of {actorMeta.total_pages}
                      </span>
                      <button
                        type="button"
                        onClick={() =>
                          actorMeta.page < actorMeta.total_pages && void runActorSearch(actorMeta.page + 1)
                        }
                        disabled={actorSearching || actorMeta.page >= actorMeta.total_pages}
                      >
                        Next
                      </button>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>

        <div style={{ alignSelf: "end", display: "flex", alignItems: "center" }}>
          <button type="submit" disabled={!canSubmit} aria-disabled={!canSubmit}>
            Apply
          </button>
          <a
            href={csvHref}
            style={{ marginLeft: "0.5rem" }}
            aria-disabled={state === "loading"}
            onClick={(e) => {
              if (state === "loading") e.preventDefault();
            }}
          >
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
                const cat = String(it.category ?? "");
                const act = String(it.action ?? "");
                const info = actionInfo(act, cat);
                return (
                  <tr key={id}>
                    <td>{id}</td>
                    <td>{String(ts)}</td>
                    <td>{info.category || cat}</td>
                    <td>
                      <span style={chipStyle(info.variant)} aria-label={`${info.label} (${act})`}>
                        {info.label}
                      </span>
                      <div style={{ fontFamily: "monospace", fontSize: "0.8rem", opacity: 0.8 }}>{act}</div>
                    </td>
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
