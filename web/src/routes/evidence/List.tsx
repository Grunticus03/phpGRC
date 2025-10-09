import { useEffect, useMemo, useRef, useState } from "react";
import { useLocation } from "react-router-dom";
import {
  deleteEvidence,
  downloadEvidenceFile,
  listEvidence,
  uploadEvidence,
  type Evidence,
  type EvidenceListOk,
} from "../../lib/api/evidence";
import { searchUsers, type UserSummary, type UserSearchOk, type UserSearchMeta } from "../../lib/api/rbac";
import { DEFAULT_TIME_FORMAT, normalizeTimeFormat, type TimeFormat } from "../../lib/format";
import { HttpError } from "../../lib/api";
import { primeUsers } from "../../lib/usersCache";
import EvidenceTable from "./EvidenceTable";

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
  const [timeFormat, setTimeFormat] = useState<TimeFormat>(DEFAULT_TIME_FORMAT);
  const [items, setItems] = useState<Evidence[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [prevStack, setPrevStack] = useState<string[]>([]);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);
  const [downloadError, setDownloadError] = useState<string | null>(null);
  const [uploading, setUploading] = useState<boolean>(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [uploadSuccess, setUploadSuccess] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deleteSuccess, setDeleteSuccess] = useState<string | null>(null);

  const location = useLocation();
  const fileInputRef = useRef<HTMLInputElement | null>(null);

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
        primeUsers(ok.data);
      } else {
        setOwnerResults([]);
        setOwnerMeta(null);
      }
    } finally {
      setOwnerSearching(false);
    }
  }

  function selectOwner(u: UserSummary) {
    primeUsers([u]);
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

  async function load(resetCursor: boolean = false, overrides?: Partial<typeof params>) {
    if (!isDateOrderValid) {
      setError("From must be on or before To");
      setState("error");
      return;
    }
    setState("loading");
    setError("");
    setDownloadError(null);
    try {
      const effectiveParams = resetCursor
        ? { ...params, ...overrides, cursor: null }
        : { ...params, ...overrides };
      const res = await listEvidence(effectiveParams);
      if (res.ok) {
        const ok = res as EvidenceListOk;
        setTimeFormat((prev) => (ok.time_format ? normalizeTimeFormat(ok.time_format) : prev));
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

  async function handleDownload(item: Evidence) {
    setDownloadError(null);
    setDownloadingId(item.id);
    try {
      await downloadEvidenceFile(item);
    } catch (err) {
      let message = "Download failed. Please try again.";
      if (err instanceof HttpError) {
        const body = (err.body ?? null) as Record<string, unknown> | null;
        const msgValue = body?.["message"];
        const codeValue = body?.["code"];
        const msg = typeof msgValue === "string" ? msgValue : null;
        const code = typeof codeValue === "string" ? codeValue : null;
        if (msg) {
          message = `Download failed: ${msg}`;
        } else if (code) {
          message = `Download failed: ${code}`;
        } else {
          message = `Download failed (HTTP ${err.status}).`;
        }
      }
      setDownloadError(message);
    } finally {
      setDownloadingId(null);
    }
  }

  async function handleUpload(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const input = fileInputRef.current;
    if (!input || !input.files || input.files.length === 0) {
      setUploadError("Please choose a file to upload.");
      setUploadSuccess(null);
      return;
    }

    const file = input.files[0];
    setUploading(true);
    setUploadError(null);
    setUploadSuccess(null);
    try {
      const res = await uploadEvidence(file);
      const name = res.name?.trim() !== "" ? res.name.trim() : res.id;
      setUploadSuccess(`${name} uploaded successfully.`);
      input.value = "";
      await load(true);
    } catch (err) {
      let message = "Upload failed. Please try again.";
      if (err instanceof HttpError) {
        const body = (err.body ?? null) as Record<string, unknown> | null;
        const msgValue = body?.["message"];
        const codeValue = body?.["code"];
        const msg = typeof msgValue === "string" ? msgValue : null;
        const code = typeof codeValue === "string" ? codeValue : null;
        if (msg) {
          message = `Upload failed: ${msg}`;
        } else if (code) {
          message = `Upload failed: ${code}`;
        } else {
          message = `Upload failed (HTTP ${err.status}).`;
        }
      } else if (err instanceof Error && err.message) {
        message = err.message;
      }
      setUploadError(message);
    } finally {
      setUploading(false);
    }
  }

  async function handleDelete(item: Evidence) {
    if (deletingId) return;
    const name = item.filename?.trim() !== "" ? item.filename.trim() : item.id;
    const confirmed = window.confirm(`Delete ${name}? This cannot be undone.`);
    if (!confirmed) return;

    setDeleteError(null);
    setDeleteSuccess(null);
    setDeletingId(item.id);
    try {
      await deleteEvidence(item.id);
      setDeleteSuccess(`${name} deleted.`);
      await load(true);
    } catch (err) {
      let message = "Delete failed. Please try again.";
      if (err instanceof HttpError) {
        const body = (err.body ?? null) as Record<string, unknown> | null;
        const msgValue = body?.["message"];
        const codeValue = body?.["code"];
        const msg = typeof msgValue === "string" ? msgValue : null;
        const code = typeof codeValue === "string" ? codeValue : null;
        if (msg) {
          message = `Delete failed: ${msg}`;
        } else if (code) {
          message = `Delete failed: ${code}`;
        } else {
          message = `Delete failed (HTTP ${err.status}).`;
        }
      } else if (err instanceof Error && err.message) {
        message = err.message;
      }
      setDeleteError(message);
    } finally {
      setDeletingId(null);
    }
  }

  useEffect(() => {
    void load(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    const paramsFromUrl = new URLSearchParams(location.search);
    const nextMime = paramsFromUrl.get("mime") ?? "";
    if (nextMime === mime) return;

    setMime(nextMime);
    setCursor(null);
    setPrevStack([]);
    void load(true, { mime: nextMime || undefined });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.search]);

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

      <section className="mb-4">
        <h2 className="h5">Upload Evidence</h2>
        <form
          onSubmit={handleUpload}
          className="row g-3 align-items-end"
          encType="multipart/form-data"
          aria-label="Upload evidence"
        >
          <div className="col-12 col-md-6 col-lg-4">
            <label htmlFor="evidence-file" className="form-label">
              File
            </label>
            <input
              ref={fileInputRef}
              type="file"
              id="evidence-file"
              className="form-control"
              required
              disabled={uploading}
            />
          </div>
          <div className="col-12 col-md-auto">
            <button type="submit" className="btn btn-primary" disabled={uploading}>
              {uploading ? "Uploading…" : "Upload"}
            </button>
          </div>
        </form>
        {uploadError && (
          <div className="alert alert-danger mt-2" role="alert">
            {uploadError}
          </div>
        )}
        {uploadSuccess && (
          <div className="alert alert-success mt-2" role="status">
            {uploadSuccess}
          </div>
        )}
      </section>

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
      {downloadError && <div className="alert alert-danger mt-3" role="alert">{downloadError}</div>}
      {deleteError && <div className="alert alert-danger mt-3" role="alert">{deleteError}</div>}
      {deleteSuccess && (
        <div className="alert alert-success mt-3" role="status">
          {deleteSuccess}
        </div>
      )}

      <EvidenceTable
        items={items}
        fetchState={state}
        timeFormat={timeFormat}
        onDownload={handleDownload}
        downloadingId={downloadingId}
        onDelete={handleDelete}
        deletingId={deletingId}
      />

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

