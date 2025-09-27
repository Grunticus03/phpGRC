import { useEffect, useMemo, useState } from "react";
import { listEvidence, type Evidence, type EvidenceListOk } from "../../lib/api/evidence";
import { searchUsers, type UserSummary, type UserSearchOk, type UserSearchMeta } from "../../lib/api/rbac";

type FetchState = "idle" | "loading" | "error" | "ok";

function chipStyle(): React.CSSProperties {
  return {
    display: "inline-block",
    fontSize: "0.85rem",
    padding: "0.15rem 0.5rem",
    borderRadius: "999px",
    border: "1px solid #bee5eb",
    lineHeight: 1.2,
    whiteSpace: "nowrap",
    color: "#0c5460",
    backgroundColor: "#e2f0f3",
  };
}

export default function EvidenceList(): JSX.Element {
  // Filters
  const [ownerInput, setOwnerInput] = useState("");
  const [ownerSelected, setOwnerSelected] = useState<UserSummary | null>(null);
  const [createdFrom, setCreatedFrom] = useState("");
  const [createdTo, setCreatedTo] = useState("");
  const [filename, setFilename] = useState("");
  const [mime, setMime] = useState("");
  const [order, setOrder] = useState<"asc" | "desc">("desc");
  const [limit, setLimit] = useState<number>(20);

  // Owner search
  const [ownerResults, setOwnerResults] = useState<UserSummary[]>([]);
  const [ownerMeta, setOwnerMeta] = useState<UserSearchMeta | null>(null);
  const [ownerPage, setOwnerPage] = useState<number>(1);
  const [ownerSearching, setOwnerSearching] = useState<boolean>(false);
  const ownerPerPage = 10;

  // Listing
  const [state, setState] = useState<FetchState>("idle");
  const [error, setError] = useState<string>("");
  const [items, setItems] = useState<Evidence[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [prevStack, setPrevStack] = useState<string[]>([]);

  const created_from = createdFrom ? `${createdFrom}T00:00:00Z` : undefined;
  const created_to = createdTo ? `${createdTo}T23:59:59Z` : undefined;

  const params = useMemo(
    () => ({
      owner_id: ownerSelected ? ownerSelected.id : undefined,
      filename: filename || undefined,
      mime: mime || undefined,
      created_from,
      created_to,
      order,
      limit,
      cursor,
    }),
    [ownerSelected, filename, mime, created_from, created_to, order, limit, cursor]
  );

  const isDateOrderValid = useMemo(() => {
    if (!createdFrom || !createdTo) return true;
    return createdFrom <= createdTo;
  }, [createdFrom, createdTo]);

  async function runOwnerSearch(targetPage?: number) {
    if (!ownerInput.trim()) return;
    setOwnerSearching(true);
    try {
      const p = typeof targetPage === "number" ? targetPage : ownerPage;
      const res = await searchUsers(ownerInput.trim(), p, ownerPerPage);
      if (res.ok) {
        const ok = res as UserSearchOk;
        setOwnerResults(ok.data);
        setOwnerMeta(ok.meta);
        setOwnerPage(ok.meta.page);
      } else {
        setOwnerResults([]);
        setOwnerMeta(null);
      }
    } finally {
      setOwnerSearching(false);
    }
  }

  function selectOwner(u: UserSummary) {
    setOwnerSelected(u);
    setOwnerResults([]);
    setOwnerMeta(null);
    setOwnerInput("");
  }

  function clearOwner() {
    setOwnerSelected(null);
    setOwnerResults([]);
    setOwnerMeta(null);
    setOwnerInput("");
  }

  async function load(resetCursor: boolean = false) {
    if (!isDateOrderValid) {
      setError("From must be on or before To");
      setState("error");
      return;
    }
    setState("loading");
    setError("");
    try {
      const res = await listEvidence(resetCursor ? { ...params, cursor: null } : params);
      if (res.ok) {
        const ok = res as EvidenceListOk;
        setItems(ok.data);
        if (resetCursor) {
          setPrevStack([]);
        }
        setCursor(ok.next_cursor);
        setState("ok");
      } else {
        setItems([]);
        setState("error");
        setError(`${res.code}${res.message ? " - " + res.message : ""}`);
      }
    } catch {
      setItems([]);
      setState("error");
      setError("Request failed");
    }
  }

  useEffect(() => {
    void load(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function nextPage() {
    if (!cursor) return;
    setPrevStack((s) => [...s, cursor as string]);
    // Use cursor in params; server will return the next cursor
    void load(false);
  }

  function prevPage() {
    if (prevStack.length === 0) return;
    const prev = prevStack[prevStack.length - 1];
    setPrevStack((s) => s.slice(0, -1));
    setCursor(prev);
    void load(false);
  }

  return (
    <main className="container py-3">
      <h1 className="mb-3">Evidence</h1>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          setCursor(null);
          void load(true);
        }}
        className="row g-3"
        aria-label="Evidence filters"
      >
        <div className="col-12">
          <label htmlFor="owner" className="form-label">Owner</label>
          {ownerSelected ? (
            <div className="d-flex align-items-center gap-2">
              <span style={chipStyle()}>{ownerSelected.name} &lt;{ownerSelected.email}&gt; • id {ownerSelected.id}</span>
              <button type="button" className="btn btn-link p-0" onClick={clearOwner} aria-label="Clear owner">Clear</button>
            </div>
          ) : (
            <div className="d-flex gap-2">
              <input
                id="owner"
                value={ownerInput}
                onChange={(e) => setOwnerInput(e.currentTarget.value)}
                className="form-control"
                placeholder="name or email"
              />
              <button
                type="button"
                className="btn btn-outline-secondary"
                onClick={() => { setOwnerPage(1); void runOwnerSearch(1); }}
                disabled={ownerSearching || !ownerInput.trim()}
              >
                {ownerSearching ? "Searching…" : "Search"}
              </button>
            </div>
          )}
        </div>

        <div className="col-12 col-md-4">
          <label htmlFor="from" className="form-label">From</label>
          <input id="from" type="date" className="form-control" value={createdFrom} onChange={(e) => setCreatedFrom(e.currentTarget.value)} />
        </div>
        <div className="col-12 col-md-4">
          <label htmlFor="to" className="form-label">To</label>
          <input id="to" type="date" className="form-control" value={createdTo} onChange={(e) => setCreatedTo(e.currentTarget.value)} />
          {!isDateOrderValid && <div className="text-danger small mt-1">To must be ≥ From</div>}
        </div>

        <div className="col-12 col-md-4">
          <label htmlFor="filename" className="form-label">Filename</label>
          <input id="filename" className="form-control" value={filename} onChange={(e) => setFilename(e.currentTarget.value)} />
        </div>

        <div className="col-12 col-md-4">
          <label htmlFor="mime" className="form-label">MIME</label>
          <input id="mime" className="form-control" value={mime} onChange={(e) => setMime(e.currentTarget.value)} />
        </div>

        <div className="col-6 col-md-2">
          <label htmlFor="order" className="form-label">Order</label>
          <select id="order" className="form-select" value={order} onChange={(e) => setOrder(e.currentTarget.value as "asc" | "desc")}>
            <option value="desc">desc</option>
            <option value="asc">asc</option>
          </select>
        </div>

        <div className="col-6 col-md-2">
          <label htmlFor="limit" className="form-label">Limit</label>
          <input
            id="limit"
            type="number"
            inputMode="numeric"
            min={1}
            max={100}
            className="form-control"
            value={limit}
            onChange={(e) => {
              const v = Number(e.currentTarget.value) || 20;
              const clamped = Math.max(1, Math.min(100, v));
              setLimit(clamped);
            }}
          />
        </div>

        <div className="col-12">
          <button type="submit" className="btn btn-primary" disabled={state === "loading"}>Apply</button>
        </div>
      </form>

      {ownerResults.length > 0 && (
        <div className="table-responsive mt-3" aria-label="Owner search results">
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
              {ownerResults.map((u) => (
                <tr key={u.id}>
                  <td>{u.id}</td>
                  <td>{u.name}</td>
                  <td>{u.email}</td>
                  <td>
                    <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => selectOwner(u)}>Select</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {ownerMeta && (
            <nav aria-label="Owner pagination" className="d-flex align-items-center gap-2">
              <button
                type="button"
                className="btn btn-outline-secondary btn-sm"
                onClick={() => { if (ownerMeta.page > 1) void runOwnerSearch(ownerMeta.page - 1); }}
                disabled={ownerSearching || ownerMeta.page <= 1}
              >
                Prev
              </button>
              <span>
                Page {ownerMeta.page} of {ownerMeta.total_pages} • {ownerMeta.total} total
              </span>
              <button
                type="button"
                className="btn btn-outline-secondary btn-sm"
                onClick={() => { if (ownerMeta.page < ownerMeta.total_pages) void runOwnerSearch(ownerMeta.page + 1); }}
                disabled={ownerSearching || ownerMeta.page >= ownerMeta.total_pages}
              >
                Next
              </button>
            </nav>
          )}
        </div>
      )}

      <hr className="my-4" />

      {state === "loading" && <p>Loading…</p>}
      {state === "error" && <p role="alert" className="text-danger">Error: {error}</p>}

      <div className="table-responsive">
        <table className="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Owner</th>
              <th>Filename</th>
              <th>MIME</th>
              <th>Size</th>
              <th>Version</th>
              <th>Created</th>
              <th>SHA-256</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 && state === "ok" ? (
              <tr>
                <td colSpan={8}>No results</td>
              </tr>
            ) : (
              items.map((e) => (
                <tr key={e.id}>
                  <td style={{ fontFamily: "monospace" }}>{e.id}</td>
                  <td>{e.owner_id}</td>
                  <td>{e.filename}</td>
                  <td>{e.mime}</td>
                  <td>{e.size}</td>
                  <td>{e.version}</td>
                  <td>{e.created_at}</td>
                  <td style={{ fontFamily: "monospace" }}>{e.sha256.slice(0, 12)}…</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <nav aria-label="Evidence pagination" className="d-flex align-items-center gap-2">
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={prevPage} disabled={state !== "ok" || prevStack.length === 0}>
          Prev
        </button>
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={nextPage} disabled={state !== "ok" || !cursor}>
          Next
        </button>
      </nav>
    </main>
  );
}

