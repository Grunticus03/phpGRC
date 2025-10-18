import { useCallback, useEffect, useMemo, useRef, useState, type ChangeEvent } from "react";
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
import { DEFAULT_TIME_FORMAT, formatBytes, normalizeTimeFormat, type TimeFormat } from "../../lib/format";
import { HttpError } from "../../lib/api";
import { primeUsers } from "../../lib/usersCache";
import EvidenceTable, { type HeaderConfig, type EvidenceSortKey } from "./EvidenceTable";
import useColumnToggle from "../../components/table/useColumnToggle";
import DateRangePicker from "../../components/pickers/DateRangePicker";
import { useToast } from "../../components/toast/ToastProvider";
import ConfirmModal from "../../components/modal/ConfirmModal";

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

type FilterKey = "created" | "owner" | "filename" | "mime" | "sha";

const evidenceDisplayName = (item: Evidence): string => {
  const name = item.filename?.trim();
  return name && name !== "" ? name : item.id;
};

export default function EvidenceList(): JSX.Element {
  // Filters
  const [ownerInput, setOwnerInput] = useState("");
  const [ownerSelected, setOwnerSelected] = useState<UserSummary | null>(null);
  const [createdFrom, setCreatedFrom] = useState("");
  const [createdTo, setCreatedTo] = useState("");
  const [filename, setFilename] = useState("");
  const [mime, setMime] = useState("");
  const [mimeFilterType, setMimeFilterType] = useState<"label" | "raw" | null>(null);
  const [sha, setSha] = useState("");
  const [tableSearch, setTableSearch] = useState("");
  const [sortState, setSortState] = useState<{ key: EvidenceSortKey | null; direction: "asc" | "desc" }>({
    key: null,
    direction: "desc",
  });
  const [limit, setLimit] = useState<number>(20);
  const { activeKey: activeFilter, toggle: toggleFilter, reset: resetActiveFilter } = useColumnToggle<FilterKey>();
  const [createdFromDraft, setCreatedFromDraft] = useState("");
  const [createdToDraft, setCreatedToDraft] = useState("");
  const [filenameDraft, setFilenameDraft] = useState("");
  const [mimeDraft, setMimeDraft] = useState("");
  const [shaDraft, setShaDraft] = useState("");
  const [limitDraft, setLimitDraft] = useState("20");
  const [dateRangeError, setDateRangeError] = useState<string | null>(null);

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
  const [uploading, setUploading] = useState<boolean>(false);
  const [uploadProgress, setUploadProgress] = useState<{ loaded: number; total: number; filename: string } | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(() => new Set());
  const toast = useToast();
  const { success: showSuccess, danger: showDanger } = toast;
  const [deleteCandidate, setDeleteCandidate] = useState<Evidence | null>(null);
  const [bulkDeletePromptOpen, setBulkDeletePromptOpen] = useState(false);

  const location = useLocation();
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const ownerInputRef = useRef<HTMLInputElement | null>(null);
  const filenameInputRef = useRef<HTMLInputElement | null>(null);
  const mimeInputRef = useRef<HTMLInputElement | null>(null);
  const shaInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    if (activeFilter === "owner") {
      ownerInputRef.current?.focus();
    } else if (activeFilter === "filename") {
      filenameInputRef.current?.focus();
      filenameInputRef.current?.select();
    } else if (activeFilter === "mime") {
      mimeInputRef.current?.focus();
      mimeInputRef.current?.select();
    } else if (activeFilter === "sha") {
      shaInputRef.current?.focus();
      shaInputRef.current?.select();
    }
  }, [activeFilter]);

  useEffect(() => {
    setFilenameDraft(filename);
  }, [filename]);

  useEffect(() => {
    setMimeDraft(mime);
  }, [mime]);

  useEffect(() => {
    setCreatedFromDraft(createdFrom);
  }, [createdFrom]);

  useEffect(() => {
    setCreatedToDraft(createdTo);
  }, [createdTo]);

  useEffect(() => {
    setLimitDraft(String(limit));
  }, [limit]);

  useEffect(() => {
    setShaDraft(sha);
  }, [sha]);

  const created_from = createdFrom ? `${createdFrom}T00:00:00Z` : undefined;
  const created_to = createdTo ? `${createdTo}T23:59:59Z` : undefined;

  const params = useMemo(() => {
    const shaClean = sha.trim().toLowerCase().replace(/[^a-f0-9]/g, "");
    let shaExact: string | undefined;
    let shaPrefix: string | undefined;
    if (shaClean.length >= 64) {
      shaExact = shaClean.slice(0, 64);
    } else if (shaClean !== "") {
      shaPrefix = shaClean;
    }

    const mimeParam = mimeFilterType === "raw" ? mime || undefined : undefined;
    const mimeLabelParam = mimeFilterType === "label" ? mime || undefined : undefined;

    return {
      owner_id: ownerSelected ? ownerSelected.id : undefined,
      filename: filename || undefined,
      mime: mimeParam,
      mime_label: mimeLabelParam,
      created_from,
      created_to,
      order: "desc" as const,
      limit,
      cursor,
      sha256: shaExact || undefined,
      sha256_prefix: shaPrefix || undefined,
    };
  }, [ownerSelected, filename, mime, mimeFilterType, created_from, created_to, limit, cursor, sha]);

  const isDateOrderValid = useMemo(() => {
    if (!createdFrom || !createdTo) return true;
    return createdFrom <= createdTo;
  }, [createdFrom, createdTo]);

  async function runOwnerSearch(targetPage?: number, opts?: { autoApply?: boolean }) {
    const query = ownerInput.trim();
    if (!query) {
      setOwnerResults([]);
      setOwnerMeta(null);
      setOwnerPage(1);
      return;
    }
    setOwnerSearching(true);
    try {
      const p = typeof targetPage === "number" ? targetPage : ownerPage;
      const res = await searchUsers(query, p, ownerPerPage);
      if (res.ok) {
        const ok = res as UserSearchOk;
        setOwnerResults(ok.data);
        setOwnerMeta(ok.meta);
        setOwnerPage(ok.meta.page);
        primeUsers(ok.data);
        if (opts?.autoApply && ok.data.length === 1) {
          applyOwnerFilter(ok.data[0]);
        }
      } else {
        setOwnerResults([]);
        setOwnerMeta(null);
      }
    } finally {
      setOwnerSearching(false);
    }
  }

  function applyOwnerFilter(user: UserSummary) {
    primeUsers([user]);
    setOwnerSelected(user);
    setOwnerResults([]);
    setOwnerMeta(null);
    setOwnerInput("");
    setOwnerPage(1);
    setCursor(null);
    setPrevStack([]);
    resetActiveFilter();
    void load(true, { owner_id: user.id });
  }

  function clearOwnerFilter(apply: boolean = true) {
    setOwnerSelected(null);
    setOwnerResults([]);
    setOwnerMeta(null);
    setOwnerInput("");
    setOwnerPage(1);
    if (apply) {
      setCursor(null);
      setPrevStack([]);
      resetActiveFilter();
      void load(true, { owner_id: undefined });
    }
  }

  async function load(resetCursor: boolean = false, overrides?: Partial<typeof params>) {
    if (!isDateOrderValid) {
      setDateRangeError("From must be on or before To");
      setError("From must be on or before To");
      setState("error");
      return;
    }
    setDateRangeError(null);
    setState("loading");
    setError("");
    try {
      const effectiveParams = resetCursor
        ? { ...params, ...overrides, cursor: null }
        : { ...params, ...overrides };
      const res = await listEvidence(effectiveParams);
      if (res.ok) {
        const ok = res as EvidenceListOk;
        setTimeFormat((prev) => (ok.time_format ? normalizeTimeFormat(ok.time_format) : prev));
        setItems(ok.data);
        setSelectedIds((prev) => {
          if (prev.size === 0) return prev;
          const allowed = new Set(ok.data.map((item) => item.id));
          let changed = false;
          const next = new Set<string>();
          for (const id of prev) {
            if (allowed.has(id)) {
              next.add(id);
            } else {
              changed = true;
            }
          }
          return changed ? next : prev;
        });
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

  function cursorForCurrentPage(): string | null {
    if (prevStack.length === 0) {
      return null;
    }
    return prevStack[prevStack.length - 1] ?? null;
  }

  async function reloadCurrentPage() {
    const pageCursor = cursorForCurrentPage();
    await load(false, { cursor: pageCursor });
  }

  async function handleDownload(item: Evidence) {
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
      showDanger(message);
    } finally {
      setDownloadingId(null);
    }
  }

  function extractBodyMessage(body: unknown): string | null {
    if (body && typeof body === "object") {
      const msg = (body as Record<string, unknown>).message;
      if (typeof msg === "string" && msg.trim() !== "") {
        return msg.trim();
      }
    }
    if (typeof body === "string" && body.trim() !== "") {
      return body.trim();
    }
    return null;
  }

  function extractValidationDetail(errors: unknown): string | null {
    if (!errors || typeof errors !== "object") {
      return null;
    }
    for (const value of Object.values(errors as Record<string, unknown>)) {
      if (Array.isArray(value)) {
        const found = value.find((item) => typeof item === "string" && item.trim() !== "");
        if (typeof found === "string") {
          return found.trim();
        }
      } else if (typeof value === "string" && value.trim() !== "") {
        return value.trim();
      }
    }
    return null;
  }

  function extractValidationMessage(body: Record<string, unknown> | null): string | null {
    if (!body) {
      return null;
    }
    const detail = extractValidationDetail(body.errors ?? null);
    if (detail) {
      return `Upload validation failed: ${detail}`;
    }
    const msg = body.message;
    return typeof msg === "string" && msg.trim() !== "" ? msg.trim() : null;
  }

  function formatDeleteError(err: unknown): string {
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
    return message;
  }

  async function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    if (uploading) return;
    const input = event.currentTarget;
    const fileList = input.files;
    if (!fileList || fileList.length === 0) {
      return;
    }

    const files = Array.from(fileList);
    const sizeForProgress = (file: File): number => {
      const size = file.size;
      return Number.isFinite(size) && size > 0 ? size : 1;
    };
    const nameForDisplay = (file: File, index: number): string => {
      const trimmed = file.name?.trim();
      if (trimmed) return trimmed;
      return `File ${index + 1}`;
    };

    const fileUnits = files.map(sizeForProgress);
    const aggregateTotal = fileUnits.reduce((sum, value) => sum + value, 0) || files.length;
    let uploadedBytes = 0;

    setUploading(true);
    if (files.length > 0) {
      setUploadProgress({
        loaded: 0,
        total: aggregateTotal,
        filename: nameForDisplay(files[0], 0),
      });
    }

    const uploadedNames: string[] = [];
    try {
      for (const [index, file] of files.entries()) {
        const progressSize = fileUnits[index] ?? sizeForProgress(file);
        const displayName = nameForDisplay(file, index);
        const res = await uploadEvidence(file, {
          onProgress: ({ loaded, total }) => {
            const perFileTotal = total && total > 0 ? total : progressSize;
            const effectiveLoaded = Math.min(perFileTotal, loaded);
            setUploadProgress({
              loaded: Math.min(aggregateTotal, uploadedBytes + effectiveLoaded),
              total: aggregateTotal,
              filename: displayName,
            });
          },
        });
        uploadedBytes += progressSize;
        setUploadProgress({
          loaded: Math.min(aggregateTotal, uploadedBytes),
          total: aggregateTotal,
          filename: displayName,
        });

        const resolvedName =
          typeof res.name === "string" && res.name.trim() !== "" ? res.name.trim() : res.id;
        uploadedNames.push(resolvedName);
      }
      if (uploadedNames.length === 1) {
        showSuccess(`${uploadedNames[0]} uploaded successfully.`);
      } else if (uploadedNames.length > 1) {
        showSuccess(`${uploadedNames.length} files uploaded successfully.`);
      }
      input.value = "";
      await load(true);
    } catch (err) {
      input.value = "";
      if (uploadedNames.length === 1) {
        showSuccess(`${uploadedNames[0]} uploaded successfully.`);
      } else if (uploadedNames.length > 1) {
        showSuccess(`${uploadedNames.length} files uploaded successfully.`);
      }
      let message = "Upload failed. Please try again.";
      if (err instanceof HttpError) {
        const rawBody = err.body ?? null;
        const body = rawBody && typeof rawBody === "object" ? (rawBody as Record<string, unknown>) : null;
        if (err.status === 422) {
          message = extractValidationMessage(body) ?? "Upload validation failed. Please check the selected file.";
        } else if (err.status === 413) {
          message = extractBodyMessage(rawBody) ?? "Upload greater than the configured limit.";
        } else {
          const msg = extractBodyMessage(rawBody);
          if (msg) {
            message = `Upload failed: ${msg}`;
          } else if (body) {
            const codeValue = body.code;
            if (typeof codeValue === "string" && codeValue.trim() !== "") {
              message = `Upload failed: ${codeValue.trim()}`;
            } else if (typeof err.message === "string" && err.message.trim() !== "") {
              message = err.message;
            }
          } else if (typeof err.message === "string" && err.message.trim() !== "") {
            message = err.message;
          } else {
            message = `Upload failed (HTTP ${err.status}).`;
          }
        }
      } else if (err instanceof Error && err.message) {
        message = err.message;
      }
      showDanger(message);
      await load(true);
    } finally {
      setUploading(false);
      setUploadProgress(null);
    }
  }

  function handleDelete(item: Evidence) {
    if (deletingId || bulkDeleting) return;
    setDeleteCandidate(item);
  }

  const deleteCandidateBusy = deleteCandidate !== null && deletingId === deleteCandidate.id;
  const deleteCandidateLabel = deleteCandidate ? evidenceDisplayName(deleteCandidate) : "";

  const dismissDeleteCandidate = (): void => {
    if (deleteCandidateBusy) return;
    setDeleteCandidate(null);
  };

  async function confirmDeleteCandidate() {
    if (!deleteCandidate || deleteCandidateBusy || bulkDeleting) {
      return;
    }

    const target = deleteCandidate;
    const label = evidenceDisplayName(target);

    setDeletingId(target.id);
    try {
      await deleteEvidence(target.id);
      showSuccess(`${label} deleted.`);
      setSelectedIds((prev) => {
        if (!prev.has(target.id)) return prev;
        const next = new Set(prev);
        next.delete(target.id);
        return next;
      });
      await reloadCurrentPage();
    } catch (err) {
      showDanger(formatDeleteError(err));
    } finally {
      setDeletingId(null);
      setDeleteCandidate(null);
    }
  }

  function handleDeleteSelected() {
    if (selectedIds.size === 0 || deletingId || bulkDeleting) {
      return;
    }
    setBulkDeletePromptOpen(true);
  }

  const cancelBulkDeletePrompt = (): void => {
    if (bulkDeleting) return;
    setBulkDeletePromptOpen(false);
  };

  async function confirmBulkDelete() {
    if (selectedIds.size === 0 || deletingId || bulkDeleting) {
      setBulkDeletePromptOpen(false);
      return;
    }

    setBulkDeleting(true);
    try {
      const ids = Array.from(selectedIds);
      const deleted: string[] = [];
      let failureMessage: string | null = null;

      for (const id of ids) {
        try {
          await deleteEvidence(id);
          deleted.push(id);
        } catch (err) {
          failureMessage = formatDeleteError(err);
          break;
        }
      }

      if (deleted.length > 0) {
        showSuccess(`Deleted ${deleted.length} item${deleted.length === 1 ? "" : "s"}.`);
        setSelectedIds((prev) => {
          const next = new Set(prev);
          for (const id of deleted) {
            next.delete(id);
          }
          return next;
        });
        await reloadCurrentPage();
      }

      if (failureMessage) {
        showDanger(failureMessage);
      }
    } finally {
      setBulkDeleting(false);
      setBulkDeletePromptOpen(false);
    }
  }

  useEffect(() => {
    void load(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    const paramsFromUrl = new URLSearchParams(location.search);
    const nextMimeLabel = paramsFromUrl.get("mime_label") ?? "";
    const nextMimeRaw = paramsFromUrl.get("mime") ?? "";

    let nextType: "label" | "raw" | null = null;
    let nextValue = "";
    if (nextMimeLabel.trim() !== "") {
      nextType = "label";
      nextValue = nextMimeLabel;
    } else if (nextMimeRaw.trim() !== "") {
      nextType = "raw";
      nextValue = nextMimeRaw;
    }

    if (nextType === mimeFilterType && nextValue === mime) return;

    setMime(nextValue);
    setMimeFilterType(nextType);
    setCursor(null);
    setPrevStack([]);
    const overrides =
      nextType === "label"
        ? { mime_label: nextValue || undefined, mime: undefined }
        : nextType === "raw"
        ? { mime: nextValue || undefined, mime_label: undefined }
        : { mime: undefined, mime_label: undefined };
    void load(true, overrides);
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

  const hasActiveFilters =
    ownerSelected !== null ||
    filename.trim() !== "" ||
    (mimeFilterType !== null && mime.trim() !== "") ||
    sha.trim() !== "" ||
    createdFrom.trim() !== "" ||
    createdTo.trim() !== "" ||
    limit !== 20 ||
    tableSearch.trim() !== "" ||
    sortState.key !== null;

  const handleToggleFilter = useCallback(
    (key: FilterKey) => {
      const willActivate = activeFilter !== key;
      if (willActivate) {
        if (key === "created") {
          setCreatedFromDraft(createdFrom);
          setCreatedToDraft(createdTo);
          setDateRangeError(null);
        } else if (key === "filename") {
          setFilenameDraft(filename);
        } else if (key === "mime") {
          setMimeDraft(mime);
        } else if (key === "sha") {
          setShaDraft(sha);
        }
      } else if (key === "owner") {
        setOwnerResults([]);
        setOwnerMeta(null);
      }
      toggleFilter(key);
    },
    [activeFilter, createdFrom, createdTo, filename, mime, sha, toggleFilter]
  );

  function applyFilenameFilter(value: string) {
    const trimmed = value.trim();
    setFilename(trimmed);
    setFilenameDraft(trimmed);
    setCursor(null);
    setPrevStack([]);
    void load(true, { filename: trimmed || undefined });
    resetActiveFilter();
  }

  function applyMimeFilter(value: string) {
    const trimmed = value.trim();
    setMime(trimmed);
    setMimeDraft(trimmed);
    setMimeFilterType(trimmed === "" ? null : "label");
    setCursor(null);
    setPrevStack([]);
    void load(true, { mime_label: trimmed || undefined, mime: undefined });
    resetActiveFilter();
  }

  function applyShaFilter(value: string) {
    const cleaned = value.trim().toLowerCase().replace(/[^a-f0-9]/g, "");
    setSha(cleaned);
    setShaDraft(cleaned);
    setCursor(null);
    setPrevStack([]);
    void load(true, {
      sha256: cleaned.length >= 64 ? cleaned.slice(0, 64) : undefined,
      sha256_prefix: cleaned !== "" && cleaned.length < 64 ? cleaned : undefined,
    });
    resetActiveFilter();
  }

  function applyCreatedFilter(fromValue: string, toValue: string, closeAfter: boolean = true) {
    const trimmedFrom = fromValue.trim();
    const trimmedTo = toValue.trim();
    if (trimmedFrom && trimmedTo && trimmedFrom > trimmedTo) {
      setDateRangeError("From must be on or before To");
      return;
    }
    setDateRangeError(null);
    setCreatedFrom(trimmedFrom);
    setCreatedTo(trimmedTo);
    setCreatedFromDraft(trimmedFrom);
    setCreatedToDraft(trimmedTo);
    setCursor(null);
    setPrevStack([]);
    void load(true, {
      created_from: trimmedFrom ? `${trimmedFrom}T00:00:00Z` : undefined,
      created_to: trimmedTo ? `${trimmedTo}T23:59:59Z` : undefined,
    });
    if (closeAfter) {
      resetActiveFilter();
    }
  }

  const handleHeaderSort = useCallback((key: EvidenceSortKey) => {
    setSortState((prev) => {
      if (prev.key !== key) {
        return { key, direction: "desc" };
      }
      if (prev.direction === "desc") {
        return { key, direction: "asc" };
      }
      return { key: null, direction: "desc" };
    });
  }, []);

  function applyLimitFromDraft() {
    const trimmed = limitDraft.trim();
    if (trimmed === "") {
      setLimitDraft(String(limit));
      return;
    }
    const parsed = Number(trimmed);
    if (Number.isNaN(parsed) || !Number.isFinite(parsed)) {
      setLimitDraft(String(limit));
      return;
    }
    const clamped = Math.max(1, Math.min(100, Math.trunc(parsed)));
    if (clamped === limit) {
      setLimitDraft(String(clamped));
      return;
    }
    setLimit(clamped);
    setLimitDraft(String(clamped));
    setCursor(null);
    setPrevStack([]);
    void load(true, { limit: clamped });
  }

  function clearAllFilters() {
    resetActiveFilter();
    setDateRangeError(null);
    clearOwnerFilter(false);
    setFilename("");
    setFilenameDraft("");
    setMime("");
    setMimeDraft("");
    setMimeFilterType(null);
    setSha("");
    setShaDraft("");
    setCreatedFrom("");
    setCreatedTo("");
    setCreatedFromDraft("");
    setCreatedToDraft("");
    setTableSearch("");
    setSortState({ key: null, direction: "desc" });
    setLimit(20);
    setLimitDraft("20");
    setCursor(null);
    setPrevStack([]);
    void load(true, {
      owner_id: undefined,
      filename: undefined,
      mime: undefined,
      mime_label: undefined,
      sha256: undefined,
      sha256_prefix: undefined,
      created_from: undefined,
      created_to: undefined,
      limit: 20,
    });
  }

  const ownerSummaryLabel = ownerSelected
    ? [ownerSelected.name?.trim() || null, ownerSelected.email?.trim() ? `<${ownerSelected.email.trim()}>` : null, `id ${ownerSelected.id}`]
        .filter((part): part is string => !!part)
        .join(" ")
    : "";

  const ownerSummaryContent = ownerSelected ? (
    <div className="mt-1">
      <span style={chipStyle()}>{ownerSummaryLabel}</span>
    </div>
  ) : null;

  const ownerFilterContent =
    activeFilter === "owner" ? (
      <div className="mt-2">
        {ownerSelected && (
          <div className="d-flex align-items-center gap-2 mb-2">
            <span style={chipStyle()}>{ownerSummaryLabel}</span>
            <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => clearOwnerFilter()}>
              Clear
            </button>
          </div>
        )}
        <label htmlFor="filter-owner" className="visually-hidden">
          Filter by owner
        </label>
        <input
          ref={ownerInputRef}
          id="filter-owner"
          className="form-control form-control-sm"
          value={ownerInput}
          onChange={(e) => setOwnerInput(e.currentTarget.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              setOwnerPage(1);
              void runOwnerSearch(1, { autoApply: true });
            } else if (e.key === "Escape") {
              e.preventDefault();
              resetActiveFilter();
            }
          }}
          placeholder="Name or email"
          autoComplete="off"
        />
        <div className="form-text">Supports * wildcards.</div>
        <div className="d-flex gap-2 mt-2">
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => {
              setOwnerPage(1);
              void runOwnerSearch(1, { autoApply: true });
            }}
            disabled={ownerSearching || ownerInput.trim() === ""}
          >
            {ownerSearching ? "Searching…" : "Search"}
          </button>
          {!ownerSelected && (
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={() => {
                setOwnerInput("");
                setOwnerResults([]);
                setOwnerMeta(null);
                setOwnerPage(1);
              }}
            >
              Reset
            </button>
          )}
        </div>
        {!ownerSearching && ownerInput.trim() !== "" && ownerResults.length === 0 && (
          <div className="small text-muted mt-2">No matches.</div>
        )}
        {ownerResults.length > 0 && (
          <ul className="list-unstyled mt-3 mb-0">
            {ownerResults.map((u) => (
              <li key={u.id} className="d-flex align-items-center justify-content-between gap-2 mb-2">
                <div>
                  <div className="small fw-semibold">{u.name?.trim() || u.email || `id ${u.id}`}</div>
                  <div className="small text-muted">
                    {u.email?.trim() ? `${u.email} • id ${u.id}` : `id ${u.id}`}
                  </div>
                </div>
                <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => applyOwnerFilter(u)}>
                  Select
                </button>
              </li>
            ))}
          </ul>
        )}
        {ownerMeta && ownerResults.length > 0 && (
          <div className="d-flex align-items-center gap-2 mt-2">
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={() => {
                if (ownerMeta.page > 1) {
                  void runOwnerSearch(ownerMeta.page - 1);
                }
              }}
              disabled={ownerSearching || ownerMeta.page <= 1}
            >
              Prev
            </button>
            <span className="small text-muted">
              Page {ownerMeta.page} of {ownerMeta.total_pages} • {ownerMeta.total} total
            </span>
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              onClick={() => {
                if (ownerMeta.page < ownerMeta.total_pages) {
                  void runOwnerSearch(ownerMeta.page + 1);
                }
              }}
              disabled={ownerSearching || ownerMeta.page >= ownerMeta.total_pages}
            >
              Next
            </button>
          </div>
        )}
      </div>
    ) : null;

  const filenameSummaryContent = filename ? (
    <div className="small text-muted mt-1">{filename}</div>
  ) : null;

  const filenameFilterContent =
    activeFilter === "filename" ? (
      <div className="mt-2">
        <label htmlFor="filter-filename" className="visually-hidden">
          Filter by filename
        </label>
        <input
          ref={filenameInputRef}
          id="filter-filename"
          className="form-control form-control-sm"
          value={filenameDraft}
          onChange={(e) => setFilenameDraft(e.currentTarget.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              applyFilenameFilter(e.currentTarget.value);
            } else if (e.key === "Escape") {
              e.preventDefault();
              resetActiveFilter();
            }
          }}
          placeholder="e.g. report*.pdf"
          autoComplete="off"
        />
        <div className="form-text">Supports * wildcards.</div>
        <div className="d-flex gap-2 mt-2">
          <button type="button" className="btn btn-primary btn-sm" onClick={() => applyFilenameFilter(filenameDraft)}>
            Apply
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => {
              setFilenameDraft("");
              applyFilenameFilter("");
            }}
          >
            Clear
          </button>
        </div>
      </div>
    ) : null;

  const mimeSummaryContent = mime ? (
    <div className="small text-muted mt-1">{mime}</div>
  ) : null;

  const mimeFilterContent =
    activeFilter === "mime" ? (
      <div className="mt-2">
        <label htmlFor="filter-mime" className="visually-hidden">
          Filter by MIME
        </label>
        <input
          ref={mimeInputRef}
          id="filter-mime"
          className="form-control form-control-sm"
          value={mimeDraft}
          onChange={(e) => setMimeDraft(e.currentTarget.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              applyMimeFilter(e.currentTarget.value);
            } else if (e.key === "Escape") {
              e.preventDefault();
              resetActiveFilter();
            }
          }}
          placeholder="e.g. image/*"
          autoComplete="off"
        />
        <div className="form-text">Supports family wildcards like image/*.</div>
        <div className="d-flex gap-2 mt-2">
          <button type="button" className="btn btn-primary btn-sm" onClick={() => applyMimeFilter(mimeDraft)}>
            Apply
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => {
              setMimeDraft("");
              applyMimeFilter("");
            }}
          >
            Clear
          </button>
        </div>
      </div>
    ) : null;

  const shaCleanDisplay = sha.trim().toLowerCase().replace(/[^a-f0-9]/g, "");
  const shaSummaryContent = shaCleanDisplay ? (
    <div className="small text-muted mt-1">{shaCleanDisplay.length > 12 ? `${shaCleanDisplay.slice(0, 12)}…` : shaCleanDisplay}</div>
  ) : null;

  const shaFilterContent =
    activeFilter === "sha" ? (
      <div className="mt-2">
        <label htmlFor="filter-sha" className="visually-hidden">
          Filter by SHA-256
        </label>
        <input
          ref={shaInputRef}
          id="filter-sha"
          className="form-control form-control-sm"
          value={shaDraft}
          onChange={(e) => setShaDraft(e.currentTarget.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              applyShaFilter(e.currentTarget.value);
            } else if (e.key === "Escape") {
              e.preventDefault();
              resetActiveFilter();
            }
          }}
          placeholder="e.g. 7f9c2b"
          autoComplete="off"
        />
        <div className="form-text">Partial hashes match by prefix.</div>
        <div className="d-flex gap-2 mt-2">
          <button type="button" className="btn btn-primary btn-sm" onClick={() => applyShaFilter(shaDraft)}>
            Apply
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => {
              setShaDraft("");
              applyShaFilter("");
            }}
          >
            Clear
          </button>
        </div>
      </div>
    ) : null;

  const createdSummaryContent =
    createdFrom || createdTo ? (
      <div className="small text-muted mt-1">
        {createdFrom ? createdFrom : "Any"}
        {createdTo ? ` → ${createdTo}` : createdFrom ? " → …" : ""}
      </div>
    ) : null;

  const createdFilterContent =
    activeFilter === "created" ? (
      <div className="mt-2 d-flex flex-column gap-3">
        <DateRangePicker
          start={createdFromDraft}
          end={createdToDraft}
          onChange={(startValue, endValue) => {
            setCreatedFromDraft(startValue);
            setCreatedToDraft(endValue);
            setDateRangeError(null);
          }}
          onComplete={(startValue, endValue) => {
            setDateRangeError(null);
            applyCreatedFilter(startValue, endValue);
          }}
          onClear={() => {
            setDateRangeError(null);
            setCreatedFromDraft("");
            setCreatedToDraft("");
            applyCreatedFilter("", "", false);
          }}
        />
        {dateRangeError && <div className="text-danger small">{dateRangeError}</div>}
      </div>
    ) : null;

  const sortDisabled = state === "loading";
  const buildSortAriaLabel = (label: string, key: EvidenceSortKey): string => {
    if (sortState.key !== key) {
      return `Sort ${label} descending`;
    }
    if (sortState.direction === "desc") {
      return `Sort ${label} ascending`;
    }
    return `Remove sorting for ${label}`;
  };

  const tableHeaders: HeaderConfig[] = [
    {
      key: "created",
      label: "Created",
      onToggle: () => handleToggleFilter("created"),
      isActive: activeFilter === "created",
      summaryContent: createdSummaryContent,
      filterContent: createdFilterContent,
      sortState: sortState.key === "created" ? sortState.direction : null,
      onSort: () => handleHeaderSort("created"),
      sortAriaLabel: buildSortAriaLabel("Created", "created"),
      sortDisabled,
    },
    {
      key: "owner",
      label: "Owner",
      onToggle: () => handleToggleFilter("owner"),
      isActive: activeFilter === "owner",
      summaryContent: ownerSummaryContent,
      filterContent: ownerFilterContent,
      sortState: sortState.key === "owner" ? sortState.direction : null,
      onSort: () => handleHeaderSort("owner"),
      sortAriaLabel: buildSortAriaLabel("Owner", "owner"),
      sortDisabled,
    },
    {
      key: "filename",
      label: "Filename",
      onToggle: () => handleToggleFilter("filename"),
      isActive: activeFilter === "filename",
      summaryContent: filenameSummaryContent,
      filterContent: filenameFilterContent,
      sortState: sortState.key === "filename" ? sortState.direction : null,
      onSort: () => handleHeaderSort("filename"),
      sortAriaLabel: buildSortAriaLabel("Filename", "filename"),
      sortDisabled,
    },
    {
      key: "size",
      label: "Size",
      sortState: sortState.key === "size" ? sortState.direction : null,
      onSort: () => handleHeaderSort("size"),
      sortAriaLabel: buildSortAriaLabel("Size", "size"),
      sortDisabled,
    },
    {
      key: "mime",
      label: "MIME",
      onToggle: () => handleToggleFilter("mime"),
      isActive: activeFilter === "mime",
      summaryContent: mimeSummaryContent,
      filterContent: mimeFilterContent,
      sortState: sortState.key === "mime" ? sortState.direction : null,
      onSort: () => handleHeaderSort("mime"),
      sortAriaLabel: buildSortAriaLabel("MIME", "mime"),
      sortDisabled,
    },
    {
      key: "sha256",
      label: "SHA-256",
      onToggle: () => handleToggleFilter("sha"),
      isActive: activeFilter === "sha",
      summaryContent: shaSummaryContent,
      filterContent: shaFilterContent,
      className: "text-nowrap",
      sortState: sortState.key === "sha256" ? sortState.direction : null,
      onSort: () => handleHeaderSort("sha256"),
      sortAriaLabel: buildSortAriaLabel("SHA-256", "sha256"),
      sortDisabled,
    },
    {
      key: "version",
      label: "Version",
      sortState: sortState.key === "version" ? sortState.direction : null,
      onSort: () => handleHeaderSort("version"),
      sortAriaLabel: buildSortAriaLabel("Version", "version"),
      sortDisabled,
    },
  ];

  const uploadPercent =
    uploadProgress == null
      ? null
      : uploadProgress.total > 0
        ? Math.min(100, Math.round((uploadProgress.loaded / uploadProgress.total) * 100))
        : 0;

  const selectedCount = selectedIds.size;

  const handleToggleSelect = useCallback(
    (item: Evidence, checked: boolean) => {
      setSelectedIds((prev) => {
        const next = new Set(prev);
        if (checked) {
          if (next.has(item.id)) {
            return prev;
          }
          next.add(item.id);
          return next;
        }
        if (!next.has(item.id)) {
          return prev;
        }
        next.delete(item.id);
        return next;
      });
    },
    []
  );

  const handleToggleSelectAll = useCallback(
    (checked: boolean, targetItems: Evidence[]) => {
      setSelectedIds((prev) => {
        const next = new Set(prev);
        if (checked) {
          let changed = false;
          for (const item of targetItems) {
            if (!next.has(item.id)) {
              next.add(item.id);
              changed = true;
            }
          }
          return changed ? next : prev;
        }

        let changed = false;
        for (const item of targetItems) {
          if (next.delete(item.id)) {
            changed = true;
          }
        }
        return changed ? next : prev;
      });
    },
    []
  );

  return (
    <main className="container py-3">
      <h1 className="mb-3">Evidence</h1>

      <section className="mb-4">
        <div className="d-flex flex-column flex-sm-row align-items-sm-center gap-2" aria-label="Upload evidence">
          <input
            ref={fileInputRef}
            type="file"
            id="evidence-file"
            style={{ display: "none" }}
            multiple
            disabled={uploading}
            onChange={handleFileChange}
          />
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => fileInputRef.current?.click()}
            disabled={uploading}
            aria-label="Select evidence files to upload"
          >
            {uploading ? "Uploading…" : "Upload Evidence"}
          </button>
        </div>
        {uploadProgress && uploadPercent !== null && (
          <div className="mt-2" role="status" aria-live="polite">
            <div className="progress" style={{ height: "1.25rem" }}>
              <div
                className="progress-bar"
                role="progressbar"
                aria-valuenow={uploadPercent}
                aria-valuemin={0}
                aria-valuemax={100}
                style={{ width: `${uploadPercent}%` }}
              >
                {uploadPercent}%
              </div>
            </div>
            <div className="small text-muted mt-1">
              Uploading {uploadProgress.filename} • {formatBytes(uploadProgress.loaded)} / {formatBytes(uploadProgress.total)}
            </div>
          </div>
        )}
      </section>

      <hr className="my-4" />

  {state === "loading" && <p>Loading…</p>}
  {state === "error" && <p role="alert" className="text-danger">Error: {error}</p>}

      <div className="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div className="d-flex flex-wrap align-items-center gap-2 flex-grow-1">
          <div className="position-relative">
            <label htmlFor="evidence-search" className="visually-hidden">
              Search evidence
            </label>
            <input
              id="evidence-search"
              type="search"
              className="form-control form-control-sm"
              placeholder="Search evidence"
              value={tableSearch}
              onChange={(event) => setTableSearch(event.currentTarget.value)}
              autoComplete="off"
              style={{ minWidth: "14rem" }}
            />
          </div>
          <span className="small text-muted">{selectedCount > 0 ? `${selectedCount} selected` : ""}</span>
        </div>
        <div className="d-flex flex-wrap align-items-center gap-2">
          <div className="d-flex align-items-center gap-2">
            <label htmlFor="evidence-limit" className="form-label mb-0">Limit</label>
            <input
              id="evidence-limit"
              type="number"
              inputMode="numeric"
              min={1}
              max={100}
              className="form-control form-control-sm"
              style={{ width: "5rem" }}
              value={limitDraft}
              onChange={(e) => setLimitDraft(e.currentTarget.value)}
              onBlur={applyLimitFromDraft}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  applyLimitFromDraft();
                } else if (e.key === "Escape") {
                  e.preventDefault();
                  setLimitDraft(String(limit));
                }
              }}
            />
          </div>
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={clearAllFilters}
            disabled={!hasActiveFilters || state === "loading"}
          >
            Clear filters
          </button>
          <button
            type="button"
            className="btn btn-outline-danger btn-sm"
            onClick={handleDeleteSelected}
            disabled={selectedCount === 0 || bulkDeleting || deletingId !== null}
          >
            {bulkDeleting ? "Deleting…" : "Delete selected"}
          </button>
        </div>
      </div>

      <EvidenceTable
        headers={tableHeaders}
        items={items}
        fetchState={state}
        timeFormat={timeFormat}
        onDownload={handleDownload}
        downloadingId={downloadingId}
        onDelete={handleDelete}
        deletingId={deletingId}
        bulkDeleting={bulkDeleting}
        selectedIds={selectedIds}
        onToggleSelect={handleToggleSelect}
        onToggleSelectAll={handleToggleSelectAll}
        selectionDisabled={bulkDeleting || deletingId !== null}
        searchTerm={tableSearch}
        sortKey={sortState.key}
        sortDirection={sortState.direction}
      />

      <nav aria-label="Evidence pagination" className="d-flex align-items-center gap-2">
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={prevPage} disabled={state !== "ok" || prevStack.length === 0}>
          Prev
        </button>
      <button type="button" className="btn btn-outline-secondary btn-sm" onClick={nextPage} disabled={state !== "ok" || !cursor}>
        Next
      </button>
    </nav>

      {deleteCandidate && (
        <ConfirmModal
          open
          title={`Delete ${deleteCandidateLabel}?`}
          busy={deleteCandidateBusy}
          confirmLabel={deleteCandidateBusy ? "Deleting…" : "Delete"}
          confirmTone="danger"
          onCancel={dismissDeleteCandidate}
          onConfirm={() => {
            if (!deleteCandidateBusy) {
              void confirmDeleteCandidate();
            }
          }}
          disableBackdropClose={deleteCandidateBusy}
        >
          <p className="mb-0">This action cannot be undone.</p>
        </ConfirmModal>
      )}

      {bulkDeletePromptOpen && (
        <ConfirmModal
          open
          title={`Delete ${selectedCount} selected item${selectedCount === 1 ? "" : "s"}?`}
          busy={bulkDeleting}
          confirmLabel={bulkDeleting ? "Deleting…" : "Delete"}
          confirmTone="danger"
          onCancel={cancelBulkDeletePrompt}
          onConfirm={() => {
            if (!bulkDeleting) {
              void confirmBulkDelete();
            }
          }}
          disableBackdropClose={bulkDeleting}
        >
          <p className="mb-0">This action cannot be undone.</p>
        </ConfirmModal>
      )}
    </main>
  );
}
