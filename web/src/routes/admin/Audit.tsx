
import { useEffect, useMemo, useRef, useState } from "react";
import { useLocation } from "react-router-dom";
import { listCategories } from "../../lib/api/audit";
import { actionInfo, type ActionInfo } from "../../lib/auditLabels";
import { searchUsers, type UserSummary, type UserSearchOk } from "../../lib/api/rbac";
import { apiGet, type QueryInit } from "../../lib/api";
import { formatBytes, formatTimestamp, DEFAULT_TIME_FORMAT, normalizeTimeFormat, type TimeFormat } from "../../lib/formatters";

type AuditItem = {
  id?: string;
  ulid?: string;
  created_at?: string;
  ts?: string;
  category?: string;
  action?: string;
  occurred_at?: string;
  user_id?: number | string | null;
  actor_id?: number | string | null;
  actor_label?: string | null;
  entity_type?: string | null;
  entity_id?: string | null;
  ip?: string | null;
  note?: string | null;
  meta?: unknown;
  [k: string]: unknown;
};

type FetchState = "idle" | "loading" | "error" | "ok";
type FieldErrors = Record<string, string[]>;
type CategoryOption = { value: string; label: string };

const CATEGORY_LABELS: Record<string, string> = {
  SYSTEM: "System",
  RBAC: "Access Control",
  AUTH: "Authentication",
  SETTINGS: "Settings",
  EXPORTS: "Exports",
  EVIDENCE: "Evidence",
  AVATARS: "Avatars",
  AUDIT: "Audit Trail",
  SETUP: "Setup",
  METRICS: "Metrics",
  OTHER: "Other",
};

function categoryLabel(value: string): string {
  const upper = value.toUpperCase();
  if (CATEGORY_LABELS[upper]) return CATEGORY_LABELS[upper];
  const parts = upper
    .toLowerCase()
    .split(/[^a-z0-9]+/i)
    .filter(Boolean);
  if (parts.length === 0) return upper;
  return parts
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(" ");
}

function buildCategoryOptions(values: string[]): CategoryOption[] {
  const seen = new Set<string>();
  const options: CategoryOption[] = [];
  for (const raw of values) {
    const upper = String(raw ?? "").trim().toUpperCase();
    if (!upper || seen.has(upper)) continue;
    seen.add(upper);
    options.push({ value: upper, label: categoryLabel(upper) });
  }
  return options.sort((a, b) => a.label.localeCompare(b.label));
}

function buildQuery(params: QueryInit) {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v === undefined || v === null) return;
    const value = typeof v === "boolean" ? (v ? "true" : "false") : String(v);
    if (value.length > 0) qs.set(k, value);
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

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function resolveActorLabel(item: AuditItem): string {
  if (typeof item.actor_label === "string" && item.actor_label.trim() !== "") {
    return item.actor_label.trim();
  }
  if (item.user_id !== null && item.user_id !== undefined) {
    return String(item.user_id);
  }
  if (item.actor_id !== null && item.actor_id !== undefined) {
    return String(item.actor_id);
  }
  return "System";
}

function readNumber(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) return value;
  if (typeof value === "string" && value !== '' && !Number.isNaN(Number(value))) return Number(value);
  return null;
}

function buildAuditMessage(item: AuditItem, info: ActionInfo, actorLabel: string): string {
  const actor = actorLabel || "System";
  const meta = isRecord(item.meta) ? item.meta : {};
  const explicitNote = typeof item.note === "string" && item.note.trim() !== '' ? item.note.trim() : '';
  if (explicitNote) return explicitNote;

  const metaMessage = typeof meta.message === "string" && meta.message.trim() !== '' ? meta.message.trim() : '';
  if (metaMessage) return metaMessage;

  const formatValue = (value: unknown): string => {
    if (value === null) return "null";
    if (value === undefined) return "n/a";
    if (typeof value === "string") return value;
    if (typeof value === "number" || typeof value === "boolean") return String(value);
    try {
      return JSON.stringify(value);
    } catch {
      return String(value);
    }
  };

  const action = item.action ?? '';
  if (action === 'setting.modified') {
    const labelCandidates = [meta.setting_label, meta.setting_key, meta.key, item.entity_id];
    const label = labelCandidates.find((v) => typeof v === 'string' && v.trim() !== '') as string | undefined;
    const settingLabel = label ?? 'Setting';
    const oldText = formatValue(meta.old_value ?? meta.old);
    const newText = formatValue(meta.new_value ?? meta.new);
    return `${settingLabel} update by ${actor}. Old: ${oldText} - New: ${newText}`;
  }

  if (action === 'settings.update') {
    const changes = Array.isArray(meta.changes) ? meta.changes.length : 0;
    if (changes > 0) {
      const noun = changes === 1 ? 'change' : 'changes';
      return `Settings updated by ${actor} (${changes} ${noun})`;
    }
    return `Settings updated by ${actor}`;
  }

  if (action === 'evidence.upload' || action === 'evidence.uploaded') {
    const filename = typeof meta.filename === 'string' && meta.filename ? meta.filename : (typeof item.entity_id === 'string' && item.entity_id ? item.entity_id : 'Evidence');
    const size = readNumber(meta.size_bytes ?? meta.size);
    const sizePart = action === 'evidence.uploaded' ? '' : (size && size > 0 ? ` (${formatBytes(size)})` : '');
    return `${filename} uploaded to evidence by ${actor}${sizePart}`;
  }

  if (action === 'evidence.downloaded') {
    const filename = typeof meta.filename === 'string' && meta.filename ? meta.filename : (typeof item.entity_id === 'string' && item.entity_id ? item.entity_id : 'Evidence');
    const size = readNumber(meta.size_bytes ?? meta.size);
    const sizePart = size && size > 0 ? ` (${formatBytes(size)})` : '';
    return `${filename} downloaded by ${actor}${sizePart}`;
  }

  if (action === 'evidence.deleted') {
    const filename = typeof meta.filename === 'string' && meta.filename ? meta.filename : (typeof item.entity_id === 'string' && item.entity_id ? item.entity_id : 'Evidence');
    return `${filename} removed from evidence by ${actor}`;
  }

  if (action === 'evidence.read' || action === 'evidence.head') {
    const filename = typeof meta.filename === 'string' && meta.filename ? meta.filename : (typeof item.entity_id === 'string' && item.entity_id ? item.entity_id : 'Evidence');
    return `${filename} viewed by ${actor}`;
  }

  if (action === 'auth.login') {
    return `${actor} logged in`;
  }

  if (action.startsWith('auth.')) {
    return `${info.label} by ${actor}`;
  }

  if (action.startsWith('rbac.user_role.')) {
    const role = typeof meta.role === 'string' ? meta.role : '';
    return role ? `${info.label}: ${role} by ${actor}` : `${info.label} by ${actor}`;
  }

  const entityType = typeof item.entity_type === 'string' && item.entity_type ? item.entity_type : '';
  const entityId = typeof item.entity_id === 'string' && item.entity_id ? item.entity_id : '';
  if (entityType || entityId) {
    const target = [entityType, entityId].filter(Boolean).join(' ');
    const suffix = actor ? ` by ${actor}` : '';
    return target ? `${info.label} on ${target}${suffix}` : `${info.label}${suffix}`;
  }

  return actor ? `${info.label} by ${actor}` : info.label;
}

export default function Audit(): JSX.Element {
  const location = useLocation();
  const [category, setCategory] = useState("");
  const [action, setAction] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [limit, setLimit] = useState(10);
  const limitRef = useRef(limit);
  const limitInputRef = useRef<HTMLInputElement | null>(null);
  const [categoryOptions, setCategoryOptions] = useState<CategoryOption[]>([]);
  const [timeFormat, setTimeFormat] = useState<TimeFormat>(DEFAULT_TIME_FORMAT);

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


  async function load(resetCursor: boolean = false, overrides?: Partial<QueryInit>) {
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
        limit: limitRef.current,
        actor_id: selectedActor ? selectedActor.id : undefined,
        ...overrides,
      };

      const effectiveParams = resetCursor ? { ...params, cursor: null } : params;

      const data = await apiGet<unknown>("/api/audit", effectiveParams, ctrl.current.signal);
      let list: AuditItem[] = [];
      let nextTimeFormat: TimeFormat | null = null;
      if (Array.isArray(data)) {
        list = data as AuditItem[];
      } else if (data && typeof data === "object") {
        const o = data as Record<string, unknown>;
        if (Array.isArray(o.items)) list = o.items as AuditItem[];
        else if (Array.isArray(o.data)) list = o.data as AuditItem[];
        if (typeof o.time_format === "string") {
          nextTimeFormat = normalizeTimeFormat(o.time_format);
        }
      }

      setTimeFormat((prev) => nextTimeFormat ?? prev);
      setItems(list);
      setState("ok");
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
        setCategoryOptions(buildCategoryOptions(arr));
      } finally {
        // no-op
      }
      await load();
      return () => ab.abort();
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    const search = new URLSearchParams(location.search);
    const nextCategory = search.get("category") ?? "";
    const nextAction = search.get("action") ?? "";
    const nextFrom = search.get("occurred_from") ?? "";
    const nextTo = search.get("occurred_to") ?? "";

    const normalizedFrom = nextFrom ? nextFrom.slice(0, 10) : "";
    const normalizedTo = nextTo ? nextTo.slice(0, 10) : "";

    const changed =
      nextCategory !== category ||
      nextAction !== action ||
      normalizedFrom !== dateFrom ||
      normalizedTo !== dateTo;

    if (!changed) return;

    setCategory(nextCategory);
    setAction(nextAction);
    setDateFrom(normalizedFrom);
    setDateTo(normalizedTo);
    void load(true, {
      category: nextCategory || undefined,
      action: nextAction || undefined,
      occurred_from: nextFrom || undefined,
      occurred_to: nextTo || undefined,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.search]);

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

  const handleActorInputEnter = () => {
    if (!isDateOrderValid) {
      showDateOrderError();
      return;
    }
    if (selectedActor) {
      void load(true, { actor_id: selectedActor.id, limit: limitRef.current });
    } else {
      void runActorSearch();
    }
  };

  const hasCategorySelect = categoryOptions.length > 0;

  const clearDateFieldErrors = () => {
    setFieldErrors((prev) => {
      if (!prev.occurred_from && !prev.occurred_to) {
        return prev;
      }
      const next = { ...prev };
      delete next.occurred_from;
      delete next.occurred_to;
      return next;
    });
  };

  const showDateOrderError = () => {
    setFieldErrors((prev) => ({
      ...prev,
      occurred_from: ["From must be on or before To"],
      occurred_to: ["To must be on or after From"],
    }));
  };

  const applyCategoryValue = (value: string) => {
    if (!isDateOrderValid) {
      showDateOrderError();
      return;
    }
    void load(true, { category: value || undefined });
  };

  const clampLimit = (value: number): number => Math.min(100, Math.max(1, value));

  const applyLimitValue = (value: number) => {
    const next = clampLimit(value);
    limitRef.current = next;
    setLimit(next);
    if (!isDateOrderValid) {
      showDateOrderError();
      return;
    }
    void load(true, { limit: next });
  };

  useEffect(() => {
    limitRef.current = limit;
  }, [limit]);

  const resetFilters = (): void => {
    setCategory("");
    setAction("");
    setDateFrom("");
    setDateTo("");
    limitRef.current = 10;
    setLimit(10);
    setSelectedActor(null);
    setActorResults([]);
    setActorMeta(null);
    setActorQ("");
    setFieldErrors({});
    setError("");
    void load(true, {
      category: undefined,
      action: undefined,
      occurred_from: undefined,
      occurred_to: undefined,
      actor_id: undefined,
      limit: 10,
    });
  };

  return (
    <section aria-busy={state === "loading"}>
      <h1>Audit</h1>

      {state === "loading" && <p>Loading.</p>}
      {state === "error" && <p role="alert">Error: {error}</p>}

      <div style={{ overflowX: "auto" }}>
        <table aria-label="Audit events" className="table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>User</th>
              <th>IP</th>
              <th>Category</th>
              <th>Action</th>
              <th>Message</th>
              <th>ID / Limit</th>
            </tr>
            <tr className="align-top">
              <th>
                <div className="d-flex flex-column gap-2">
                  <div className="d-flex flex-wrap gap-2">
                    <label htmlFor="f-from" className="form-label visually-hidden">
                      From
                    </label>
                    <input
                      id="f-from"
                      type="date"
                      className="form-control form-control-sm"
                      value={dateFrom}
                      onChange={(e) => {
                        const nextValue = e.target.value;
                        setDateFrom(nextValue);
                        if (nextValue && dateTo && nextValue > dateTo) {
                          showDateOrderError();
                          return;
                        }
                        clearDateFieldErrors();
                        void load(true, {
                          occurred_from: nextValue ? `${nextValue}T00:00:00Z` : undefined,
                          occurred_to: dateTo ? `${dateTo}T23:59:59Z` : undefined,
                        });
                      }}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault();
                          if (isDateOrderValid) {
                            void load(true);
                          } else {
                            showDateOrderError();
                          }
                        }
                      }}
                      aria-invalid={!isDateOrderValid || !!fieldErrors.occurred_from?.length}
                    />
                    <label htmlFor="f-to" className="form-label visually-hidden">
                      To
                    </label>
                    <input
                      id="f-to"
                      type="date"
                      className="form-control form-control-sm"
                      value={dateTo}
                      onChange={(e) => {
                        const nextValue = e.target.value;
                        setDateTo(nextValue);
                        if (dateFrom && nextValue && dateFrom > nextValue) {
                          showDateOrderError();
                          return;
                        }
                        clearDateFieldErrors();
                        void load(true, {
                          occurred_from: dateFrom ? `${dateFrom}T00:00:00Z` : undefined,
                          occurred_to: nextValue ? `${nextValue}T23:59:59Z` : undefined,
                        });
                      }}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault();
                          if (isDateOrderValid) {
                            void load(true);
                          } else {
                            showDateOrderError();
                          }
                        }
                      }}
                      aria-invalid={!isDateOrderValid || !!fieldErrors.occurred_to?.length}
                    />
                  </div>
                  {!isDateOrderValid ? (
                    <p role="alert" className="text-danger small mb-0">
                      From must be ≤ To
                    </p>
                  ) : null}
                  {fieldErrors.occurred_from?.length ? (
                    <ul role="alert" className="text-danger small mb-0 ps-3">
                      {fieldErrors.occurred_from.map((m, i) => (
                        <li key={i}>{m}</li>
                      ))}
                    </ul>
                  ) : null}
                  {fieldErrors.occurred_to?.length ? (
                    <ul role="alert" className="text-danger small mb-0 ps-3">
                      {fieldErrors.occurred_to.map((m, i) => (
                        <li key={i}>{m}</li>
                      ))}
                    </ul>
                  ) : null}
                  <div className="d-flex flex-wrap gap-2">
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm"
                      onClick={() => {
                        setDateFrom("");
                        setDateTo("");
                        clearDateFieldErrors();
                        void load(true, {
                          occurred_from: undefined,
                          occurred_to: undefined,
                        });
                      }}
                      disabled={!dateFrom && !dateTo}
                    >
                      Clear dates
                    </button>
                  </div>
                </div>
              </th>
              <th>
                <div className="d-flex flex-column gap-2">
                  {selectedActor ? (
                    <div className="d-flex flex-column gap-2">
                      <div className="small">
                        <span className="fw-semibold">{selectedActor.name || selectedActor.email || `id ${selectedActor.id}`}</span>
                        <span className="text-muted ms-2">id {selectedActor.id}</span>
                      </div>
                      <button type="button" className="btn btn-outline-secondary btn-sm" onClick={clearActor}>
                        Clear actor
                      </button>
                    </div>
                  ) : (
                    <>
                      <label htmlFor="f-actor" className="form-label visually-hidden">
                        Actor
                      </label>
                      <input
                        id="f-actor"
                        className="form-control form-control-sm"
                        value={actorQ}
                        onChange={(e) => setActorQ(e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key !== "Enter") return;
                          e.preventDefault();
                          handleActorInputEnter();
                        }}
                        placeholder="Search name or email"
                      />
                      <div className="d-flex flex-wrap gap-2">
                        <button
                          type="button"
                          className="btn btn-outline-secondary btn-sm"
                          onClick={() => void runActorSearch()}
                          aria-busy={actorSearching}
                          disabled={actorSearching || actorQ.trim() === ""}
                        >
                          {actorSearching ? "Searching…" : "Find actor"}
                        </button>
                        <button
                          type="button"
                          className="btn btn-outline-secondary btn-sm"
                          onClick={() => {
                            setActorResults([]);
                            setActorMeta(null);
                            setActorQ("");
                          }}
                          disabled={actorSearching && actorResults.length === 0}
                        >
                          Clear results
                        </button>
                      </div>
                      {actorResults.length > 0 && (
                        <div className="border rounded p-2" style={{ maxHeight: "220px", overflowY: "auto" }}>
                          <ul className="list-unstyled mb-0">
                            {actorResults.map((u) => (
                              <li key={u.id} className="d-flex justify-content-between gap-2 mb-2">
                                <div>
                                  <div className="small fw-semibold">{u.name?.trim() || u.email || `id ${u.id}`}</div>
                                  {u.email && <div className="small text-muted">{u.email}</div>}
                                </div>
                                <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => selectActor(u)}>
                                  Select
                                </button>
                              </li>
                            ))}
                          </ul>
                          {actorMeta && (
                            <div className="d-flex align-items-center justify-content-between gap-2 mt-2">
                              <button
                                type="button"
                                className="btn btn-outline-secondary btn-sm"
                                onClick={() => actorMeta.page > 1 && void runActorSearch(actorMeta.page - 1)}
                                disabled={actorSearching || actorMeta.page <= 1}
                              >
                                Prev
                              </button>
                              <span className="small text-muted">
                                Page {actorMeta.page} of {actorMeta.total_pages}
                              </span>
                              <button
                                type="button"
                                className="btn btn-outline-secondary btn-sm"
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
                    </>
                  )}
                </div>
              </th>
              <th>
                <div className="d-flex flex-column gap-2">
                  <button type="button" className="btn btn-outline-secondary btn-sm" onClick={resetFilters}>
                    Reset filters
                  </button>
                </div>
              </th>
              <th>
                <div className="d-flex flex-column gap-2">
                  <label htmlFor="f-cat" className="form-label visually-hidden">
                    Category
                  </label>
                  {hasCategorySelect ? (
                    <select
                      id="f-cat"
                      className="form-select form-select-sm"
                      value={category}
                      onChange={(e) => {
                        const nextValue = e.target.value;
                        setCategory(nextValue);
                        applyCategoryValue(nextValue);
                      }}
                      aria-invalid={!!fieldErrors.category?.length}
                    >
                      <option value="">(any)</option>
                      {categoryOptions.map((c) => (
                        <option key={c.value} value={c.value}>
                          {c.label}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <input
                      id="f-cat"
                      className="form-control form-control-sm"
                      value={category}
                      onChange={(e) => setCategory(e.target.value)}
                      onBlur={(e) => applyCategoryValue(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault();
                          applyCategoryValue(e.currentTarget.value);
                        }
                      }}
                      placeholder="e.g. RBAC"
                      aria-invalid={!!fieldErrors.category?.length}
                    />
                  )}
                  {fieldErrors.category?.length ? (
                    <ul role="alert" className="text-danger small mb-0 ps-3">
                      {fieldErrors.category.map((m, i) => (
                        <li key={i}>{m}</li>
                      ))}
                    </ul>
                  ) : null}
                </div>
              </th>
              <th>
                <div className="d-flex flex-column gap-2">
                  <label htmlFor="f-act" className="form-label visually-hidden">
                    Action
                  </label>
                  <input
                    id="f-act"
                    className="form-control form-control-sm"
                    value={action}
                    onChange={(e) => setAction(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") {
                        e.preventDefault();
                        if (isDateOrderValid) {
                          void load(true);
                        } else {
                          showDateOrderError();
                        }
                      }
                    }}
                    placeholder="e.g. Role attached"
                    aria-invalid={!!fieldErrors.action?.length}
                  />
                  <div className="form-text">
                    Matches action labels, e.g. "Role attached" or "Example: 9/30/2025, 5:23:01 PM".
                  </div>
                  {fieldErrors.action?.length ? (
                    <ul role="alert" className="text-danger small mb-0 ps-3">
                      {fieldErrors.action.map((m, i) => (
                        <li key={i}>{m}</li>
                      ))}
                    </ul>
                  ) : null}
                </div>
              </th>
              <th>
                <div className="d-flex flex-wrap gap-2">
                  <button
                    type="button"
                    className="btn btn-outline-secondary btn-sm"
                    onClick={() => {
                      if (!isDateOrderValid) {
                        showDateOrderError();
                        return;
                      }
                      void load(true, { limit: limitRef.current });
                    }}
                    disabled={state === "loading"}
                    aria-disabled={state === "loading"}
                  >
                    Refresh
                  </button>
                </div>
              </th>
              <th>
                <div className="d-flex flex-column gap-2">
                  <label htmlFor="f-limit" className="form-label visually-hidden">
                    Limit
                  </label>
                  <input
                    ref={limitInputRef}
                    id="f-limit"
                    type="number"
                    min={1}
                    max={100}
                    step={1}
                    className="form-control form-control-sm"
              value={limit}
              onChange={(e) => {
                const raw = e.target.value;
                if (raw === "") {
                  limitRef.current = 10;
                  setLimit(10);
                  return;
                }
                const parsed = Number(raw);
                if (Number.isNaN(parsed) || parsed < 1) {
                  limitRef.current = 1;
                  setLimit(1);
                  return;
                }
                applyLimitValue(parsed);
              }}
                    onKeyDown={(e) => {
                      if (e.key === "Enter") {
                        e.preventDefault();
                        applyLimitValue(limitRef.current || 10);
                      }
                    }}
                    aria-invalid={!!fieldErrors.limit?.length}
                  />
                  {fieldErrors.limit?.length ? (
                    <ul role="alert" className="text-danger small mb-0 ps-3">
                      {fieldErrors.limit.map((m, i) => (
                        <li key={i}>{m}</li>
                      ))}
                    </ul>
                  ) : null}
                </div>
              </th>
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
                const tsRaw =
                  (typeof it.occurred_at === "string" && it.occurred_at) ||
                  (typeof it.created_at === "string" && it.created_at) ||
                  (typeof it.ts === "string" && it.ts) ||
                  "";
                const ts = formatTimestamp(tsRaw, timeFormat);
                const actor = resolveActorLabel(it);
                const cat = String(it.category ?? "");
                const info = actionInfo(String(it.action ?? ""), cat);
                const categoryDisplay = categoryLabel(info.category || cat);
                const message = buildAuditMessage(it, info, actor);

                return (
                  <tr key={id}>
                    <td title={tsRaw}>{ts}</td>
                    <td>{actor}</td>
                    <td>{String(it.ip ?? "")}</td>
                    <td>{categoryDisplay}</td>
                    <td>
                      <span
                        style={chipStyle(info.variant)}
                        aria-label={`${info.label} (${it.action ?? ''})`}
                        title={String(it.action ?? "")}
                      >
                        {info.label}
                      </span>
                    </td>
                    <td>{message}</td>
                    <td style={{ fontFamily: "monospace" }}>{id}</td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      <div style={{ marginTop: "0.75rem" }}>
        <a
          href={csvHref}
          className="btn btn-outline-secondary btn-sm"
          aria-disabled={state === "loading"}
          onClick={(e) => {
            if (state === "loading") e.preventDefault();
          }}
        >
          Download CSV
        </a>
      </div>
    </section>
  );
}
